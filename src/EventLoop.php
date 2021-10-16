<?php

namespace Revolt;

use Revolt\EventLoop\Driver;
use Revolt\EventLoop\DriverFactory;
use Revolt\EventLoop\Internal\AbstractDriver;
use Revolt\EventLoop\Internal\Callback;
use Revolt\EventLoop\InvalidWatcherError;
use Revolt\EventLoop\Suspension;
use Revolt\EventLoop\UnsupportedFeatureException;

/**
 * Accessor to allow global access to the event loop.
 *
 * @see Driver
 */
final class EventLoop
{
    private static Driver $driver;
    private static ?\Fiber $fiber;

    /**
     * Sets the driver to be used as the event loop.
     */
    public static function setDriver(Driver $driver): void
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (isset(self::$driver) && self::$driver->isRunning()) {
            throw new \Error("Can't swap the event loop driver while the driver is running");
        }

        self::$fiber = null;

        try {
            /** @psalm-suppress InternalClass */
            self::$driver = new class () extends AbstractDriver {
                protected function activate(array $callbacks): void
                {
                    throw new \Error("Can't activate callback during garbage collection.");
                }

                protected function dispatch(bool $blocking): void
                {
                    throw new \Error("Can't dispatch during garbage collection.");
                }

                protected function deactivate(Callback $callback): void
                {
                    // do nothing
                }

                public function getHandle(): mixed
                {
                    return null;
                }

                protected function now(): float
                {
                    return now();
                }
            };

            \gc_collect_cycles();
        } finally {
            self::$driver = $driver;
        }
    }

    /**
     * Queue a microtask.
     *
     * The queued callable MUST be executed immediately once the event loop gains control. Order of queueing MUST be
     * preserved when executing the callbacks. Recursive scheduling can thus result in infinite loops, use with care.
     *
     * Does NOT create an event callback, thus CAN NOT be marked as disabled or unreferenced.
     * Use {@see EventLoop::defer()} if you need these features.
     *
     * @param callable $callback The callback to queue.
     * @param mixed    ...$args The callback arguments.
     */
    public static function queue(callable $callback, mixed ...$args): void
    {
        self::getDriver()->queue($callback, ...$args);
    }

    /**
     * Defer the execution of a callback.
     *
     * The deferred callable MUST be executed before any other type of callback in a tick. Order of enabling MUST be
     * preserved when executing the callbacks.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Deferred callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param callable(string) $callback The callback to defer. The `$callbackId` will be
     *     invalidated before the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function defer(callable $callback): string
    {
        return self::getDriver()->defer($callback);
    }

    /**
     * Delay the execution of a callback.
     *
     * The delay is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be determined by which
     * timers expire first, but timers with the same expiration time MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param float   $delay The amount of time, in seconds, to delay the execution for.
     * @param callable(string) $callback The callback to delay. The `$callbackId` will be invalidated before
     *     the callback invocation.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function delay(float $delay, callable $callback): string
    {
        return self::getDriver()->delay($delay, $callback);
    }

    /**
     * Repeatedly execute a callback.
     *
     * The interval between executions is a minimum and approximate, accuracy is not guaranteed. Order of calls MUST be
     * determined by which timers expire first, but timers with the same expiration time MAY be executed in any order.
     * The first execution is scheduled after the first interval period.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param float   $interval The time interval, in seconds, to wait between executions.
     * @param callable(string) $callback The callback to repeat.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function repeat(float $interval, callable $callback): string
    {
        return self::getDriver()->repeat($interval, $callback);
    }

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * callback when closing the resource locally. Drivers MAY choose to notify the user if there are callbacks on
     * invalid resources, but are not required to, due to the high performance impact. Callbacks on closed resources are
     * therefore undefined behavior.
     *
     * Multiple callbacks on the same stream MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource|object) $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function onReadable(mixed $stream, callable $callback): string
    {
        return self::getDriver()->onReadable($stream, $callback);
    }

    /**
     * Execute a callback when a stream resource becomes writable or is closed for writing.
     *
     * Warning: Closing resources locally, e.g. with `fclose`, might not invoke the callback. Be sure to `cancel` the
     * callback when closing the resource locally. Drivers MAY choose to notify the user if there are callbacks on
     * invalid resources, but are not required to, due to the high performance impact. Callbacks on closed resources are
     * therefore undefined behavior.
     *
     * Multiple callbacks on the same stream MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param resource|object $stream The stream to monitor.
     * @param callable(string, resource|object) $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     */
    public static function onWritable(mixed $stream, callable $callback): string
    {
        return self::getDriver()->onWritable($stream, $callback);
    }

    /**
     * Execute a callback when a signal is received.
     *
     * Warning: Installing the same signal on different instances of this interface is deemed undefined behavior.
     * Implementations MAY try to detect this, if possible, but are not required to. This is due to technical
     * limitations of the signals being registered globally per process.
     *
     * Multiple callbacks on the same signal MAY be executed in any order.
     *
     * The created callback MUST immediately be marked as enabled, but only be activated (i.e. callback can be called)
     * right before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param int   $signo The signal number to monitor.
     * @param callable(string, int) $callback The callback to execute.
     *
     * @return string A unique identifier that can be used to cancel, enable or disable the callback.
     *
     * @throws UnsupportedFeatureException If signal handling is not supported.
     */
    public static function onSignal(int $signo, callable $callback): string
    {
        return self::getDriver()->onSignal($signo, $callback);
    }

    /**
     * Enable a callback to be active starting in the next tick.
     *
     * Callbacks MUST immediately be marked as enabled, but only be activated (i.e. callbacks can be called) right
     * before the next tick. Callbacks MUST NOT be called in the tick they were enabled.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidWatcherError If the callback identifier is invalid.
     */
    public static function enable(string $callbackId): string
    {
        return self::getDriver()->enable($callbackId);
    }

    /**
     * Disable a callback immediately.
     *
     * A callback MUST be disabled immediately, e.g. if a deferred callback disables another deferred callback,
     * the second deferred callback isn't executed in this tick.
     *
     * Disabling a callback MUST NOT invalidate the callback. Calling this function MUST NOT fail, even if passed an
     * invalid callback identifier.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public static function disable(string $callbackId): string
    {
        return self::getDriver()->disable($callbackId);
    }

    /**
     * Cancel a callback.
     *
     * This will detach the event loop from all resources that are associated to the callback. After this operation the
     * callback is permanently invalid. Calling this function MUST NOT fail, even if passed an invalid identifier.
     *
     * @param string $callbackId The callback identifier.
     */
    public static function cancel(string $callbackId): void
    {
        self::getDriver()->cancel($callbackId);
    }

    /**
     * Reference a callback.
     *
     * This will keep the event loop alive whilst the event is still being monitored. Callbacks have this state by
     * default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     *
     * @throws InvalidWatcherError If the callback identifier is invalid.
     */
    public static function reference(string $callbackId): string
    {
        return self::getDriver()->reference($callbackId);
    }

    /**
     * Unreference a callback.
     *
     * The event loop should exit the run method when only unreferenced callbacks are still being monitored. Callbacks
     * are all referenced by default.
     *
     * @param string $callbackId The callback identifier.
     *
     * @return string The callback identifier.
     */
    public static function unreference(string $callbackId): string
    {
        return self::getDriver()->unreference($callbackId);
    }

    /**
     * Set a callback to be executed when an error occurs.
     *
     * The callback receives the error as the first and only parameter. The return value of the callback gets ignored.
     * If it can't handle the error, it MUST throw the error. Errors thrown by the callback or during its invocation
     * MUST be thrown into the `run` loop and stop the driver.
     *
     * Subsequent calls to this method will overwrite the previous handler.
     *
     * @param callable(\Throwable)|null $callback The callback to execute. `null` will clear the
     *     current handler.
     *
     * @return callable(\Throwable)|null The previous handler, `null` if there was none.
     */
    public static function setErrorHandler(callable $callback = null): ?callable
    {
        return self::getDriver()->setErrorHandler($callback);
    }

    /**
     * Retrieve an associative array of information about the event loop driver.
     *
     * The returned array MUST contain the following data describing the driver's currently registered callbacks:
     *
     *     [
     *         "defer"            => ["enabled" => int, "disabled" => int],
     *         "delay"            => ["enabled" => int, "disabled" => int],
     *         "repeat"           => ["enabled" => int, "disabled" => int],
     *         "on_readable"      => ["enabled" => int, "disabled" => int],
     *         "on_writable"      => ["enabled" => int, "disabled" => int],
     *         "on_signal"        => ["enabled" => int, "disabled" => int],
     *         "enabled_watchers" => ["referenced" => int, "unreferenced" => int],
     *         "running"          => bool
     *     ];
     *
     * Implementations MAY optionally add more information in the array but at minimum the above `key => value` format
     * MUST always be provided.
     *
     * @return array Statistics about the loop in the described format.
     */
    public static function getInfo(): array
    {
        return self::getDriver()->getInfo();
    }

    /**
     * Retrieve the event loop driver that is in scope.
     *
     * @return Driver
     */
    public static function getDriver(): Driver
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck, RedundantCondition */
        if (!isset(self::$driver)) {
            self::setDriver((new DriverFactory())->create());
        }

        return self::$driver;
    }

    /**
     * Create an object used to suspend and resume execution, either within a fiber or from {main}.
     *
     * @return Suspension
     */
    public static function createSuspension(): Suspension
    {
        /** @psalm-suppress RedundantPropertyInitializationCheck */
        if (!isset(self::$fiber) || self::$fiber->isTerminated()) {
            if (!\class_exists(\Fiber::class, false)) {
                throw new \Error("Fibers required to create loop suspensions");
            }

            self::$fiber = self::createFiber();
        }

        return new Suspension(self::getDriver(), self::$fiber);
    }

    /**
     * Run the event loop.
     *
     * This function may only be called from {main}, that is, not within a fiber.
     *
     * Libraries should use the {@link Suspension} API instead of calling this method.
     *
     * This method will not return until the event loop does not contain any pending, referenced callbacks anymore.
     */
    public static function run(): void
    {
        if (!\class_exists(\Fiber::class, false)) {
            if (self::getDriver()->isRunning()) {
                throw new \Error("The event loop is already running");
            }

            self::getDriver()->run();
            return;
        }

        if (\Fiber::getCurrent()) {
            throw new \Error(\sprintf("Can't call %s() within a fiber (i.e., outside of {main})", __METHOD__));
        }

        if (!self::$fiber || self::$fiber->isTerminated()) {
            self::$fiber = self::createFiber();
        }

        if (self::$fiber->isStarted()) {
            self::$fiber->resume();
        } else {
            self::$fiber->start();
        }
    }

    /**
     * Creates a fiber to run the active driver instance using {@see Driver::run()}.
     *
     * @return \Fiber Fiber used to run the event loop.
     */
    private static function createFiber(): \Fiber
    {
        return new \Fiber([self::getDriver(), 'run']);
    }

    /**
     * Disable construction as this is a static class.
     */
    private function __construct()
    {
        // intentionally left blank
    }
}
