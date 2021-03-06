<?php

namespace Hhxsv5\LaravelS\Swoole\Traits;

use Hhxsv5\LaravelS\Swoole\Timer\CronJob;

trait TimerTrait
{
    use ProcessTitleTrait;
    use LaravelTrait;
    use LogTrait;

    public function addTimerProcess(\swoole_server $swoole, array $config, array $laravelConfig)
    {
        if (empty($config['enable']) || empty($config['jobs'])) {
            return;
        }

        $startTimer = function (\swoole_process $process) use ($swoole, $config, $laravelConfig) {
            file_put_contents($config['pid_file'], $process->pid);
            $this->setProcessTitle(sprintf('%s laravels: timer process', $config['process_prefix']));
            $this->initLaravel($laravelConfig, $swoole);
            $timerIds = [];
            foreach ($config['jobs'] as $jobClass) {
                if (is_array($jobClass) && isset($jobClass[0])) {
                    $job = new $jobClass[0](isset($jobClass[1]) ? $jobClass[1] : []);
                } else {
                    $job = new $jobClass();
                }
                if (!($job instanceof CronJob)) {
                    throw new \Exception(sprintf(
                            '%s must extend the abstract class %s',
                            get_class($job),
                            CronJob::class
                        )
                    );
                }
                if (empty($job->interval())) {
                    throw new \Exception(sprintf('The interval of %s cannot be empty', get_class($job)));
                }
                $timerId = swoole_timer_tick($job->interval(), function () use ($job) {
                    $this->callWithCatchException(function () use ($job) {
                        $job->run();
                    });
                });
                $timerIds[] = $timerId;
                $job->setTimerId($timerId);
                if ($job->isImmediate()) {
                    swoole_timer_after(1, function () use ($job) {
                        $this->callWithCatchException(function () use ($job) {
                            $job->run();
                        });
                    });
                }
            }

            \swoole_process::signal(SIGUSR1, function ($signo) use (&$timerIds) {
                foreach ($timerIds as $timerId) {
                    swoole_timer_clear($timerId);
                }
                $timerIds = [];
            });
        };

        $timerProcess = new \swoole_process($startTimer, false, false);
        if ($swoole->addProcess($timerProcess)) {
            return $timerProcess;
        }
    }

}