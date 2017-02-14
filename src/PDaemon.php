<?php

/*
 * This file is part of the Consatan\PDaemon package.
 *
 * (c) Chopin Ngo <consatan@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Consatan\PDaemon;

/**
 * {@inheritdoc}
 */
abstract class PDaemon implements PDaemonInterface
{
    /**
     * SystemV semaphore resource
     *
     * @var resource
     */
    protected $semaphore;

    /**
     * SystemV semaphore resource key
     *
     * @var int
     */
    protected $semaphoreKey;

    /**
     * Partner process pid
     *
     * @var int
     */
    protected $pid;

    /**
     * pid file path
     *
     * @var string
     */
    protected $pidFile;

    /**
     * Options
     * `blocking`: (false) Running in blocking mode switch.
     * `daemon`: (true) Running daemon otherwise.
     * `pidFile`: (/var/run/pdaemon.pid) PID file path.
     * `pool`: (10) Fork children quantity.
     * `exec`: (null) Callback function.
     *
     * @var array
     */
    protected $options = [
        'blocking' => false,
        'daemon' => true,
        'pidFile' => '/var/run/pdaemon.pid',
        'pool' => 10,
        'exec' => null,
    ];

    /**
     * @param  array  $options  ([]) Options array
     * @see self::$options for a list of available options.
     */
    public function __construct(array $options = [])
    {
        $this->options = array_merge($this->options, $options);
        $this->pidFile = $this->options['pidFile'];
    }

    /**
     * {@inheritdoc}
     */
    public function run(callable $fn = null)
    {
        // Install signal
        $this->signal();

        if ($this->options['daemon']) {
            // If running in daemon, fork a master process
            $pid = pcntl_fork();

            if ($pid === -1) {
                exit("Could not fork.\n");
            } elseif ($pid !== 0) {
                if ($fn !== null) {
                    $fn();
                }
                // Exit current process, master process running in background
                exit(0);
            }
        }
        // unset $fn
        $fn = null;

        // Initialize options
        $this->init();

        if ($this->options['daemon']) {
            // If running in daemon, set master process to session leader
            if (posix_setsid() === -1) {
                exit("Could not set to session leader.\n");
            }
        }

        if ($this->options['blocking']) {
            // blocking
            while (@sem_acquire($this->semaphore, false)) {
                // Dispatch signal
                pcntl_signal_dispatch();

                // Fork child process
                $pid = pcntl_fork();

                if ($pid === -1) {
                    exit("Could not fork.\n");
                } elseif ($pid === 0) {
                    // Do something in child process
                    $this->exec();
                    // MUST release semaphore when child process exited
                    $this->release();
                }
            }

            exit("Acquire a semaphore failure.\n");
        } else {
            // non-blocking
            while (true) {
                // Dispatch signal
                pcntl_signal_dispatch();

                // Non-blocking acquire a semaphore
                //
                // If use blocking, when child process execute a long
                // time process, the signal CAN NOT handle until some
                // child process exited.
                $sem = @sem_acquire($this->semaphore, true);

                if ($sem === true) {
                    // Fork child process
                    $pid = pcntl_fork();

                    if ($pid === -1) {
                        exit("Could not fork.\n");
                    } elseif ($pid === 0) {
                        // Do something in child process
                        $this->exec();
                        exit(0);
                    }
                } elseif ($sem === false) {
                    if (pcntl_wait($status, WNOHANG) > 0) {
                        // MUST release semaphore when child process exited
                        $this->release(false);
                    }
                    // Not semaphore alive, sleep 0.01 seconds
                    usleep(10000);
                } else {
                    // Acquire semaphore error
                    exit("Acquire a semaphore failure.\n");
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy()
    {
        // Delete semaphore resource
        if ($this->semaphore && !sem_remove($this->semaphore)) {
            $this->log("Remove semaphore failure.\n");
        }

        // Get pid from pid file (check pid file was existed)
        $pid = file_get_contents($this->pidFile);

        // If pid file's pid equal this pid
        // ELSE just exit this process
        if ($pid !== false && intval($pid) === $this->pid) {
            // Delete pid file
            if (!unlink($this->pidFile)) {
                $this->log("Delete pid file [{$this->pidFile}] failure.\n");
            }
        }

        exit(0);
    }

    /**
     * Execute something in child process
     *
     * @return void
     */
    abstract protected function exec();

    /**
     * Check parent is terminated
     *
     * @return bool  `true` if parent process was terminated or `false`
     */
    protected function terminated()
    {
        // return !file_exists('/proc/' . posix_getpgrp());
        return !posix_kill(posix_getpgrp(), 0);
    }

    /**
     * Install any signal
     * See also `man 7 signal`
     *
     * @return void
     */
    protected function signal()
    {
        // non-blocking DONOT ignore this signal, because
        // MUST manually release semaphore when child process stopped or terminated
        if ($this->options['blocking']) {
            // Ignore SIGCHLD (child process stopped or terminated signal)
            pcntl_signal(SIGCHLD, SIG_IGN);
        }

        // Interrupt from keyboard singal
        // This signal generated when a user presses Ctrl+C.
        // v.a. http://programmergamer.blogspot.jp/2013/05/clarification-on-sigint-sigterm-sigkill.html
        pcntl_signal(SIGINT, [$this, 'destroy']);

        // Quit from keyboard singal
        // This signal generated when a user presses Ctrl+\
        pcntl_signal(SIGQUIT, [$this, 'destroy']);

        // Terminates a process immediately
        // This signal generated when run `kill PID` command
        pcntl_signal(SIGTERM, [$this, 'destroy']);
    }

    /**
     * Release semaphore
     *
     * @param  bool        $exit      (true) Exit process after release semaphore
     * @param  int|string  $exitCode  (0) Exit code or message
     * @return void
     */
    protected function release($exit = true, $exitCode = 0)
    {
        @sem_release($this->semaphore);
        // blocking mode now has a release semaphore bug, when run a long time,
        // total processes maybe less than $this->pool
        // a dirty fix is call twice sem_release()
        if ($this->options['blocking']
            // 1% probability
            && mt_rand(1, 100) === 1
            // get process sizeof, ps command output a title line and this command has a new process
            && exec("ps --ppid {$this->pid} | wc -l") < $this->options['pool'] + 2
        ) {
            @sem_release($this->semaphore);
        }

        if ((bool)$exit) {
            exit($exitCode);
        }
    }

    /**
     * Logger, default log message with `error_log` function,
     * override this method if you want to use OTHER logger
     *
     * @param  string  $message  Message
     * @return bool              Success return `true` otherwise return `false`
     */
    protected function log($message)
    {
        return error_log($message);
    }

    /**
     * Initialize options
     *
     * @return void
     */
    protected function init()
    {
        // Get master process pid
        $this->pid = posix_getpid();

        // Check if pid file path is exists
        if (@is_file($this->pidFile)) {
            // Get pid from exists pid file
            $pid = file_get_contents($this->pidFile);
            if ($pid !== false) {
                $pid = (int)$pid;
                if ($pid === $this->pid || posix_getpgid($pid) !== false) {
                    // Current process or background process is running, exit
                    exit(0);
                } else {
                    // Delete the pid file then create a new one
                    if (unlink($this->pidFile)) {
                        return $this->init();
                    } else {
                        exit("Delete pid file [{$this->pidFile}] failure.\n");
                    }
                }
            } else {
                exit("Read pid file [{$this->pidFile}] failure.\n");
            }
        } else {
            // Create a pid file lock it when write done
            if (file_put_contents($this->pidFile, $this->pid, LOCK_EX) !== false) {
                // Change pid file mode to rw-r-r
                chmod($this->pidFile, 0644);
            } else {
                exit("Create pid file [{$this->pidFile}] failure.\n");
            }
        }

        // Get or create a semaphore.
        // Semaphore key use ftok(PIDFILE, 'a'), different PIDFILE can
        // running a different instance.
        // `auto_release` flag set to false, disable semaphore auto release
        $this->semaphoreKey = ftok($this->pidFile, 'a');
        if (($this->semaphore = sem_get($this->semaphoreKey, $this->options['pool'], 0600, 0)) === false) {
            // If get or create semaphore failure, delete pid file
            if (!unlink($this->pidFile)) {
                exit("Delete pid file [{$this->pidFile}] failure.\n");
            }
            exit("Could not get semaphore id.\n");
        }
    }
}
