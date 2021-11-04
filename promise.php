<?php
/**************************************************
* author: 拓荒者 <eMuBin@126.com>
**************************************************/

class Promise
{
    private $state = 'pending';
    private $result;
    private $rejects = [];
    private $resolves = [];

    function __construct(callable $callable, ...$args)
    {
        $this->timestamp = time();

        $callable([$this, 'resolve'], [$this, 'reject'], ...$args);
        // Go($callable, [$this, 'resolve'], [$this, 'reject'], ...$args);
    }

    public function wait()
    {
        return isset($this) ? static::wait4static($this) : static::wait4static(...func_get_args());
    }

    public function then(callable $callable)
    {
        $this->resolves[] = $callable;

        $this->executecallabe();

        return $this;
    }

    public function state()
    {
        return $this->state;
    }

    public function catch(callable $callable)
    {
        $this->rejects[] = $callable;

        $this->executecallabe();

        return $this;
    }

    public function finally(callable $callable)
    {
        $this->rejects[] = $callable;

        $this->resolves[] = $callable;

        $this->executecallabe();

        return $this;
    }

    public function reject($result = null)
    {
        if ( !isset($this) ) { return static::reject4static($result); }

        $this->state = 'rejected';

        $this->result = $result;

        $this->executecallabe($result);
    }

    public function resolve($result = null)
    {
        if ( !isset($this) ) { return static::resolve4static($result); }

        $this->state = 'fulfilled';

        $this->result = $result;

        $this->executecallabe($result);
    }

    public static function all(...$args)
    {
        return new self(function($resolve, $reject) use (&$args){
            $results = [];

            $rejected = false;

            $promises = static::args2promises($args);

            $promiselen = sizeof($promises);

            foreach ($promises as $key => $promise) {
                $results[$key] = null;

                $promise->then(function($result) use (&$promiselen, &$rejected, &$results, $resolve, $key) {
                    if ( $rejected ) { return ; }

                    $results[$key] = $result;

                    if ( !--$promiselen ) { $resolve($results); }
                });

                $promise->catch(function($result) use ($reject, $key) {
                    $rejected = true; $reject($result, $key);
                });
            }
        });
    }

    public static function race(...$args)
    {
        return new self(function($resolve, $reject) use (&$args){
            $RACEd = false;

            foreach (static::args2promises($args) as $key => $promise) {
                $promise->then(function($result) use ($RACEd, $resolve, $key) {
                    !$RACEd && ($RACEd = true && $resolve($result, $key));
                });

                $promise->catch(function($result) use ($RACEd, $reject, $key) {
                    !$RACEd && ($RACEd = true && $reject($result, $key));
                });
            }
        });
    }

    public static function pipe(...$args)
    {
        $defer = static::defer();

        $pipefn = function(array $callables, $result = null) use ($defer, &$pipefn) {
            $callable = array_shift($callables);

            if ( empty($callables) ) { return $defer->resolve($result); }

            static::argv2promise($callable, $result)
                ->then(function($result) use (&$pipefn, &$callables){
                    $pipefn($callables, $result);
                })->catch(function($reason) use ($defer) {
                    $defer->reject($reason);
                });
        };

        $pipefn( static::args2expand($args) );

        return $defer->promise;
    }

    public static function defer()
    {
        return new class(__CLASS__) {
            function __construct($prototype)
            {
                $this->promise = new $prototype(function($resolve, $reject) {
                    $this->rejectproto = $reject;
                    $this->resolveproto = $resolve;
                });

                $this->prototype = $prototype;
            }

            public function wait()
            {
                return $this->prototype::wait($this->promise);
            }

            public function reject($result = null)
            {
                return call_user_func($this->rejectproto, $result);
            }

            public function resolve($result = null)
            {
                return call_user_func($this->resolveproto, $result);
            }
        };
    }

    public static function allsettled(...$args)
    {
        return new self(function($resolve, $reject) use (&$args){
            $results = [];

            $promisenum = 0;

            foreach (static::args2promises($args) as $key => $promise) {
                $results[$key] = new stdClass();

                $promisenum++;

                $promise->then(function($result) use (&$promisenum, &$results, $resolve, $key) {
                    $results[$key]->value = $result;
                    $results[$key]->status = 'fulfilled';
                    if ( !--$promisenum ) { $resolve($results); }
                });

                $promise->catch(function($result) use (&$promisenum, &$results, $resolve, $key) {
                    $results[$key]->reason = $result;
                    $results[$key]->status = 'rejected';
                    if ( !--$promisenum ) { $resolve($results); }
                });
            }
        });
    }

    public static function wait4static(...$args)
    {
        $RETVAL = null;

        $PROMISE = static::args2promise($args);

        $SUSPENDCID = null;

        $PROMISE->finally(function($response) use (&$RETVAL, &$SUSPENDCID) {
            $RETVAL = $response; !empty($SUSPENDCID) && Co::resume($SUSPENDCID);
        });

        $PROMISE->state() === 'pending' && ($SUSPENDCID = Co::getcid()) && Co::suspend();

        return $RETVAL;
    }

    private static function state4static($type, $result = null)
    {
        return new self(function($resolve, $reject)use($type, &$result){
            call_user_func($type ? $resolve : $reject, $result);
        });
    }

    private static function reject4static($result = null)
    {
        return static::state4static(0, $result);
    }

    private static function resolve4static($result = null)
    {
        return static::state4static(1, $result);
    }

    private static function ispromise($val)
    {
        return is_object($val) && $val instanceof self;
    }

    private function argv2promise($callable, $result = null)
    {
        if ( is_callable($callable) ) {
            $rf = new ReflectionFunction($callable);

            $parameters = $rf->getParameters();

            if ( empty($parameters) ) {
                $promise = $callable($result);
            }

            else if ( $parameters[0]->name === 'resolve' ) {
                $promise = new self($callable, $result);
            }

            else {
                $promise = $callable($result);
            }
        } else if ( is_int($callable) && is_array($timer = Swoole\Timer::info($callable))) {
            $promise = new self(function($resolve) use (&$timer, $callable){
                Swoole\Timer::after($timer['exec_msec'] + 10, function() use (&$timer, $resolve, $callable){
                    $resolve(true); $timer['interval'] && Swoole\Timer::clear($callable);
                });
            });
        } else {
            $promise = &$callable;
        }

        return static::ispromise($promise) ? $promise : (
                $promise ? static::resolve($promise) : static::reject($promise)
            );
    }

    private static function args2promise($args)
    {
        $promises = static::args2promises($args);

        return sizeof($promises) === 1 ? $promises[0] : static::all($promises);
    }

    private static function args2expand($args)
    {
        $_args  = [];

        foreach ($args as $argv) {
            if ( is_array($argv) && !is_callable($argv) ) {
                array_push($_args, ...static::args2expand($argv));
            } else {
                array_push($_args, $argv);
            }
        }

        return $_args;
    }

    private static function args2promises($args)
    {
        return array_map([__CLASS__, 'argv2promise'], static::args2expand($args));
    }

    private function executecallabe($result = null)
    {
        if ( $this->state === 'pending' ) { return ; }

        if ( $this->state === 'rejected' ) { $callables = &$this->rejects; } else {
            $callables = &$this->resolves;
        }

        if ( !$result ) { $result = $this->result; }

        while ( $callable = array_shift($callables) ) {
            if ( static::ispromise($callable) ) {
                $this->executepromise($callable); break;
            }

            if ( !is_callable($callable) ) { continue; }

            if ( static::ispromise($val = $callable($result)) ) {
                $this->executepromise($val); break;
            }
        }

        array_splice($this->rejects, 0);
        array_splice($this->resolves, 0);
    }

    private function executepromise($promise)
    {
        $promise->then(function($result){
            call_user_func([$this, $this->state === 'rejected' ? 'reject' : 'resolve'], $result);
        });
    }
}

function Promise(callable $callable, ...$args)
{
    return new Promise($callable, ...$args);
}
