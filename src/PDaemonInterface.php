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
 * Running PHP script in daemon mode at CLI
 *
 * @method void run(callable $fn = null)
 * @method void destroy()
 */
interface PDaemonInterface
{
    /**
     * Run the daemon process
     *
     * @param  callable  $fn  (null) The callback function executed before starting the daemon process
     * @return void
     */
    public function run(callable $fn = null);

    /**
     * Destroy resources after EXIT process.
     * Trigger this method when received SIGINT,SIGTERM,SIGQUIT signal,
     * normal remove semaphore resource and delete pid file
     *
     * If you want destroy other resource (like database or redis connect)
     * MUST override this method, or triggered this method BEFORE execute your code
     *
     * @return void
     */
    public function destroy();
}
