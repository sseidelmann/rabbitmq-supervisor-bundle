<?php

namespace Phobetor\RabbitMqSupervisorBundle\Services;

use Symfony\Component\Templating\EngineInterface;

/**
 * @license MIT
 */
class RabbitMqSupervisor
{
    /**
     * @var \Phobetor\RabbitMqSupervisorBundle\Services\Supervisor
     */
    private $supervisor;

    /**
     * @var \Symfony\Component\Templating\EngineInterface $templating
     */
    private $templating;

    /**
     * @var array
     */
    private $paths;

    /**
     * @var array
     */
    private $commands;

    /**
     * @var array
     */
    private $consumers;

    /**
     * @var array
     */
    private $multipleConsumers;

    /**
     * @var int
     */
    private $workerCount;

    /**
     * @var string
     */
    private $fileMode;

    /**
     * @var array
     */
    private $workerOptions;

    /**
     * Initialize Handler
     *
     * @param \Phobetor\RabbitMqSupervisorBundle\Services\Supervisor $supervisor
     * @param \Symfony\Component\Templating\EngineInterface $templating
     * @param array $paths
     * @param array $commands
     * @param array $consumers
     * @param array $multipleConsumers
     * @param int $workerCount
     * @param string $fileMode
     * @param array $workerOptions
     */
    public function __construct(Supervisor $supervisor, EngineInterface $templating, array $paths, array $commands, $consumers, $multipleConsumers, $workerCount, $fileMode, array $workerOptions = array())
    {
        $this->supervisor = $supervisor;
        $this->templating = $templating;
        $this->paths = $paths;
        $this->commands = $commands;
        $this->consumers = $consumers;
        $this->multipleConsumers = $multipleConsumers;
        $this->workerCount = $workerCount;
        $this->fileMode = $fileMode;
        $this->workerOptions = $workerOptions;
    }

    /**
     * Build supervisor configuration
     */
    public function init()
    {
        $this->generateSupervisorConfiguration();
    }

    /**
     * Build all supervisor worker configuration files
     */
    public function build()
    {
        $this->createPathDirectories();

        if (!is_file($this->createSupervisorConfigurationFilePath())) {
            $this->generateSupervisorConfiguration();
        }

        // remove old worker configuration files
        /** @var \SplFileInfo $item */
        foreach (new \DirectoryIterator($this->paths['worker_configuration_directory']) as $item) {
            if ($item->isDir()) {
                continue;
            }

            if ('conf' !== $item->getExtension()) {
                continue;
            }

            unlink($item->getRealPath());
        }

        // generate program configuration files for all consumers
        $this->generateWorkerConfigurations(array_keys($this->consumers), $this->commands['rabbitmq_consumer'], $this->commands['max_messages']);

        // generate program configuration files for all multiple consumers
        $this->generateWorkerConfigurations(array_keys($this->multipleConsumers), $this->commands['rabbitmq_multiple_consumer'], $this->commands['max_messages']);

        // start supervisor and reload configuration
        $this->start();
        $this->supervisor->reloadAndUpdate();
    }

    /**
     * Stop, build configuration for and start supervisord
     */
    public function rebuild()
    {
        $this->stop();
        $this->build();
    }

    /**
     * Stop and start supervisord to force all processes to restart
     */
    public function restart()
    {
        $this->stop();
        $this->start();
    }

    /**
     * Stop supervisord and all processes
     */
    public function stop()
    {
        $this->kill('', true);
    }

    /**
     * Start supervisord and all processes
     */
    public function start()
    {
        $this->supervisor->run();
    }

    /**
     * Returns the supervisord status
     * @return string
     */
    public function status()
    {
        return $this->supervisor->status();
    }

    /**
     * Send -HUP to supervisord to gracefully restart all processes
     */
    public function hup()
    {
        $this->kill('HUP');
    }

    /**
     * Send kill signal to supervisord
     *
     * @param string $signal
     * @param bool $waitForProcessToDisappear
     */
    public function kill($signal = '', $waitForProcessToDisappear = false)
    {
        $pid = $this->getSupervisorPid();
        if (!empty($pid) && $this->isProcessRunning($pid)) {
            if (!empty($signal)) {
                $signal = sprintf('-%s', $signal);
            }

            $command = sprintf('kill %s %d', $signal, $pid);

            passthru($command);

            if ($waitForProcessToDisappear) {
                $this->wait();
            }
        }
    }

    /**
     * Wait for supervisord process to disappear
     */
    public function wait()
    {
        $pid = $this->getSupervisorPid();
        if (!empty($pid)) {
            while ($this->isProcessRunning($pid)) {
                sleep(1);
            }
        }
    }

    /**
     * Check if a process with the given pid is running
     *
     * @param int $pid
     * @return bool
     */
    private function isProcessRunning($pid) {
        $state = array();
        exec(sprintf('ps %d', $pid), $state);

        /*
         * ps will return at least one row, the column labels.
         * If the process is running ps will return a second row with its status.
         */
        return 1 < count($state);
    }

    /**
     * Determines the supervisord process id
     *
     * @return null|int
     */
    private function getSupervisorPid() {

        $pidPath = $this->paths['pid_file'];

        $pid = null;
        if (is_file($pidPath) && is_readable($pidPath)) {
            $pid = (int)file_get_contents($pidPath);
        }

        return $pid;
    }

    private function createPathDirectories() {
        foreach ($this->paths as $path) {
            if ('/' !== substr($path, -1, 1)) {
                $path = dirname($path);
            }

            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    public function generateSupervisorConfiguration()
    {
        $supervisorConfigurationPath = $this->createSupervisorConfigurationFilePath();
        $content = $this->templating->render(
            'RabbitMqSupervisorBundle:Supervisor:supervisord.conf.twig',
            array(
                'pidFile' => $this->paths['pid_file'],
                'sockFile' => $this->paths['sock_file'],
                'logFile' => $this->paths['log_file'],
                'fileMode' => $this->fileMode,
                'workerConfigurationDirectory' => $this->paths['worker_configuration_directory'],
            )
        );
        file_put_contents(
            $supervisorConfigurationPath,
            $content
        );
        chmod($supervisorConfigurationPath, $this->fileMode);
    }

    private function generateWorkerConfigurations($names, $command, $maxMessages = 250)
    {
        if (0 === strpos($_SERVER["SCRIPT_FILENAME"], '/')) {
            $executablePath = $_SERVER["SCRIPT_FILENAME"];
        }
        else {
            $executablePath = sprintf('%s/%s', getcwd(), $_SERVER["SCRIPT_FILENAME"]);
        }

        foreach ($names as $name) {
            $this->generateWorkerConfiguration(
                $name,
                array(
                    'name' => $name,
                    'command' => sprintf($command, $maxMessages, $name),
                    'executablePath' => $executablePath,
                    'workerOutputLog' => $this->paths['worker_output_log_file'],
                    'workerErrorLog' => $this->paths['worker_error_log_file'],
                    'numprocs' => $this->workerCount,
                    'fileMode' => $this->fileMode,
                    'options' => $this->transformBoolsToStrings($this->workerOptions),
                )
            );
        }
    }

    /**
     * @param string $fileName file in app/supervisor dir
     * @param array $vars
     */
    public function generateWorkerConfiguration($fileName, $vars)
    {
        $workerConfigurationPath = sprintf('%s%s.conf', $this->paths['worker_configuration_directory'], $fileName);
        $content = $this->templating->render('RabbitMqSupervisorBundle:Supervisor:program.conf.twig', $vars);
        file_put_contents(
            $workerConfigurationPath,
            $content
        );
        chmod($workerConfigurationPath, $this->fileMode);
    }

    /**
     * @return string
     */
    private function createSupervisorConfigurationFilePath()
    {
        return $this->paths['configuration_file'];
    }

    /**
     * Transform bool array values to string representation.
     *
     * @param array $options
     *
     * @return array
     */
    private function transformBoolsToStrings(array $options)
    {
        $transformedOptions = array();
        foreach ($options as $key => $value) {
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $transformedOptions[$key] = $value;
        }

        return $transformedOptions;
    }
}
