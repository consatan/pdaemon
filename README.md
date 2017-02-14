#### PDaemon
Running PHP script in daemon mode at CLI. **Not available on Windows platforms**.

#### Install

##### Requirements

- PHP >= 5.4
- pcntl extension
- posix extension
- sysvsem extension

##### Use composer (recommend)

```shell
composer require consatan/pdaemon
```

##### Download from github

```shell
git clone https://consatan.github.com/pdaemon.git
```


#### How to use

Simple daemon
```php
<?php

require './vendor/autoload.php';

class DemoDaemon extends \Consatan\PDaemon\PDaemon
{
    protected function exec()
    {
        // Your code here.
        echo 'Running in children, PID: ' . posix_getpid() . PHP_EOL;
        // You can access your custom variable in $this->options
        // echo $this->options['your_variable'] . PHP_EOL;
        sleep(3);
    }
}

// Fork 3 children processes, pid file in /var/run/pdaemon.pid
$daemon = new DemoDaemon(['pool' => 3, 'your_variable' => 'hello world']);
$daemon->run();
```

You can stop daemon process list this
```php
<?php

require './vendor/autoload.php';

// replace to your pid path
$pid = file_get_contents('/var/run/pdaemon.pid');

if (false === $pid) {
    // pid file cannot read or not exists.
    exit(-1);
}

$pid = (int)$pid;

if (0 >= $pid) {
    // invalid pid
    exit(-1);
}

// This is a safe terminat function, it will stop process when all children processes stop
posix_kill($pid, SIGTERM);

// You can forced stop the daemon like this: (non-recommended);
// posix_kill($pid, SIGKILL);
```

#### Todo

- [ ] Unit test
- [ ] Handling reload signal
- [ ] Handling status signal
