<?php


namespace iPaya\Task\Worker\Commands;


use iPaya\Task\Worker\Command;
use Swoole\Client;
use Swoole\Process;
use Swoole\Server;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

class StartCommand extends Command
{
    /**
     * @var OutputInterface
     */
    public $output;
    public $workerNum;
    public $taskWorkerNum;
    public $schedulerHost;
    public $schedulerPort;

    /**
     * @var Client
     */
    public $client;

    protected function configure()
    {
        $this->setName('start')
            ->setDescription('Start task worker server.');

        $workerNum = getenv('WORKER_NUM');
        $taskWorkerNum = getenv('TASK_WORKER_NUM');
        $this->workerNum = $workerNum !== false ? $workerNum : 2;
        $this->taskWorkerNum = $taskWorkerNum !== false ? $taskWorkerNum : 8;

        $this->schedulerHost = getenv("SCHEDULER_HOST");
        $this->schedulerPort = getenv('SCHEDULER_PORT');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;

        $server = $this->createServer($this->workerNum, $this->taskWorkerNum);
        $server->start();
    }

    /**
     * @param int $workerNum
     * @param int $taskWorkerNum
     * @return Server
     */
    public function createServer(int $workerNum, int $taskWorkerNum)
    {
        $server = new Server('127.0.0.1', 0);
        $server->set([
            'task_worker_num' => $taskWorkerNum,
            'worker_num' => $workerNum,
        ]);
        $server->on('start', [$this, 'onStart']);
        $server->on('workerStart', [$this, 'onWorkerStart']);
        $server->on('receive', [$this, 'onReceive']);
        $server->on('task', [$this, 'onTask']);
        $server->on('finish', [$this, 'onFinish']);

        $server->addProcess(new Process(function (Process $process) use ($server) {
            swoole_set_process_name('task-worker:remote-client');
            $client = new Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_ASYNC);
            $client->on('receive', function (Client $client, $data) use ($server) {
                $this->output->writeln('Remote Client Receive: ' . $data, Output::VERBOSITY_DEBUG);
                $data = json_decode($data, true);

                $action = $data['action'] ?? 'unknown';
                switch ($action) {
                    case 'register/fail':
                        $errorMessage = $data['data']['errorMessage'];
                        $this->output->writeln("<error>Register fail: {$errorMessage}</error>");
                        $server->shutdown();
                        break;
                    case 'register/success':
                        $this->output->writeln("<info>Registered at task scheduler server.</info>");
                        break;
                    case 'run':
                        $command = $data['data']['command'];
                        $this->sendCommand($server, $command);
                        break;
                }
            });

            $client->on('connect', function (Client $client) {
                $this->output->writeln('Registering to task scheduler server');
                $client->send(json_encode([
                    'action' => 'register',
                    'data' => [
                        'token' => getenv('TOKEN')
                    ],
                ]));
            });

            $client->on('close', function (Client $client) use ($server) {
                $this->output->writeln("<error>Connection closed</error>");
                $server->shutdown();
            });

            $client->on('error', function (Client $client) use ($server) {
                $this->output->writeln('<error>Connect to task scheduler server failed.</error>');
                $server->shutdown();
            });

            $client->connect($this->schedulerHost, $this->schedulerPort, 1);
        }));
        return $server;
    }

    public function sendCommand(Server $server, string $command)
    {
        $this->output->writeln("Sending command '{$command}'",Output::VERBOSITY_DEBUG);
        $client = new Client(SWOOLE_SOCK_TCP, SWOOLE_SOCK_SYNC);
        if ($client->connect('127.0.0.1', $server->port)) {
            if(!$client->send($command)){
                $this->output->writeln("Failed to send command '{$command}'",Output::VERBOSITY_DEBUG);
            }else{
                $this->output->writeln("Sent command '{$command}'",Output::VERBOSITY_DEBUG);
            }

            $client->close();
        }
    }

    public function onStart(Server $server)
    {
        $app = $this->getApplication();
        $stats = $server->stats();
        $table = new Table($this->output);
        $table
            ->setRows([
                ['<comment>Version</comment>', $app->getVersion()],
                ['<comment>Start Time</comment>', date('Y-m-d H:i:s', $stats['start_time'])],
                ['<comment>Scheduler Host</comment>', $this->schedulerHost . ':' . $this->schedulerPort],
            ]);
        $table->render();

        $this->log('Started.');
    }

    public function onReceive(Server $server, int $fd, $reactorId, string $data)
    {
        $this->output->writeln('Receive: ' . $data, Output::VERBOSITY_DEBUG);
        $server->task($data);
    }

    public function onWorkerStart(Server $server, int $workerId)
    {
        if ($server->taskworker) {
            swoole_set_process_name('task-worker:task-worker #' . $workerId);
        } else {
            swoole_set_process_name('task-worker:worker #' . $workerId);
        }
    }

    public function onTask(Server $server, int $taskId, int $srcWorkerId, $command)
    {
        $this->log("[Task {$taskId}][Start] run command '$command'");
        exec($command, $output, $return);
        foreach ($output as $line) {
            $this->log("[Task {$taskId}][Output] {$line}");
        }
        return $command;
    }

    public function onFinish(Server $server, int $taskId, $command)
    {
        $this->log("[Task {$taskId}][Finish] Run command '{$command}'");
    }
}
