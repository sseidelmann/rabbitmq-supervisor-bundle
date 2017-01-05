<?php

namespace Phobetor\RabbitMqSupervisorBundle\Services;

use Symfony\Component\Process\Process;

class Supervisor
{
    private $applicationDirectory;
    private $configurationParameter;
    private $identifierParameter;

    public function __construct($applicationDirectory, $configuration, $identifier)
    {
        $this->applicationDirectory = $applicationDirectory;
        $this->configurationParameter = $configuration ? (' --configuration=' . $configuration) : '';
        $this->identifierParameter    = $identifier    ? (' --identifier='    . $identifier)    : '';
    }

    /**
     * Execute a supervisorctl command
     *
     * @param $cmd string supervisorctl command
     * @return \Symfony\Component\Process\Process
     */
    public function execute($cmd)
    {
        $p = new Process(
            sprintf(
                'supervisorctl%1$s %2$s',
                $this->configurationParameter,
                $cmd
            )
        );
        error_log('execute cmd: ' . $p->getCommandLine() . PHP_EOL, 3, '/tmp/supervisor.log');
        $p->setWorkingDirectory($this->applicationDirectory);
        $p->run();
        $p->wait(function ($type, $data) {
            error_log(' -> ' . strtoupper($type) . ' :: ' . $data . PHP_EOL, 3, '/tmp/supervisor.log');
        });
        error_log('command finsihed' . PHP_EOL, 3, '/tmp/supervisor.log');
        return $p;
    }

    /**
     * Update configuration and processes
     */
    public function reloadAndUpdate()
    {
        error_log(__METHOD__ . ' start' . PHP_EOL, 3, '/tmp/supervisor.log');
        $this->execute('reread');
        $this->execute('update');
        error_log(__METHOD__ . ' end' . PHP_EOL, 3, '/tmp/supervisor.log');
    }

    /**
     * Start supervisord if not already running
     */
    public function run()
    {
        $result = $this->execute('status')->getOutput();
        if (strpos($result, 'sock no such file') || strpos($result, 'refused connection')) {
            $p = new Process(
                sprintf(
                    'supervisord%1$s%2$s',
                    $this->configurationParameter,
                    $this->identifierParameter
                )
            );
            $p->setWorkingDirectory($this->applicationDirectory);
            $p->run();
        }
    }
}
