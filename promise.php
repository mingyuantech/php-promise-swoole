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
        // Go($callable, [$this, 'resolve'], [$this, 'reject'], ...$args);
        $callable([$this, 'resolve'], [$this, 'reject'], ...$args);
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

            $promisenum = 0;

            foreach (static::args2array($args) as $key => $callable) {
                $promise = static::callable2promise($callable);

                $results[$key] = null;

                $promisenum++;

                $promise->then(function($result) use (&$promisenum, &$rejected, &$results, $resolve, $key) {
                    if ( $rejected ) { return ; }

                    $results[$key] = $result;

                    if ( !--$promisenum ) { $resolve($results); }
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

            foreach (static::args2array($args) as $key => $callable) {
                $promise = static::callable2promise($callable);

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

            if ( !$callable ) { return $defer->resolve($result); }

            if ( !is_callable($callable) ) { return $pipefn($callables, $result); }

            static::callable2promise($callable, $result)
                ->then(function($result) use (&$pipefn, &$callables){
                    $pipefn($callables, $result);
                })->catch(function($reason) use ($defer) {
                    $defer->reject($reason);
                });
        };

        $pipefn( static::args2array($args) );

        return $defer->promise;
    }

    public static function wait($promise)
    {
        if ( !Co::exists($CID = Co::getCid()) ) {
            throw new Exception('API must be called in the coroutine');
        }

        if ( is_array($promise) || func_num_args() > 1 ) {
            $promise = static::allsettled(...func_get_args());
        }

        $promise->finally(function() use ($CID){ Co::resume($CID); });

        Co::yield();
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

            foreach (static::args2array($args) as $key => $callable) {
                $promise = static::callable2promise($callable);

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

    private function reject4static($result = null)
    {
        return static::state4static(0, $result);
    }

    private function resolve4static($result = null)
    {
        return static::state4static(1, $result);
    }

    private function ispromise($val)
    {
        return is_object($val) && $val instanceof self;
    }

    private function args2array($args)
    {
        $alls  = [];

        foreach ($args as $argv) {
            if ( is_array($argv) && !is_callable($argv) ) {
                array_push($alls, ...static::args2array($argv));
            } else {
                array_push($alls, $argv);
            }
        }

        return $alls;
    }

    private function state4static($type, $result = null)
    {
        return new self(function($resolve, $reject)use($type, &$result){
            Co::sleep(0.001); Go($type ? $resolve : $reject, $result);
        });
    }

    private function executecallabe($result = null)
    {
        if ( $this->state === 'pending' ) { return ; }

        if ( $this->state === 'rejected' ) { $callables = &$this->rejects; } else {
            $callables = &$this->resolves;
        }

        while ( $callable = array_shift($callables) ) {
            if ( static::ispromise($callable) ) {
                $this->executepromise($callable); break;
            }

            if ( !is_callable($callable) ) { continue; }

            if ( static::ispromise($val = $callable($result ?? $this->result)) ) {
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

    private function callable2promise($callable, $result = null)
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
}

function Promise(callable $callable, ...$args)
{
    return new Promise($callable, ...$args);
}
