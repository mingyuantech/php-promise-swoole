<?php
/**************************************************
* author: 拓荒者 <eMuBin@126.com>
**************************************************/


class Promise
{
    private $state = 'pending';
    private $rejects = [];
    private $resolves = [];

    function __construct(callable $callable, ...$args)
    {
        Go($callable, [$this, 'resolve'], [$this, 'reject'], ...$args);
    }

    function __invoke(callable $callable, ...$args)
    {
        return new self($callable, ...$args);
    }

    public function then($callable)
    {
        $this->resolves[] = $callable; return $this;
    }

    public function state()
    {
        return $this->state;
    }

    public function catch(callable $callable)
    {
        $this->rejects[] = $callable; return $this;
    }

    public function finally(callable $callable)
    {
        $this->rejects[] = $callable;

        $this->resolves[] = $callable;

        return $this;
    }

    public function reject($response = null)
    {
        if ( !isset($this) ) { return static::reject4static($response); }

        $this->state = 'rejected';

        $this->executecallabe($this->rejects, $response);
    }

    public function resolve($response = null)
    {
        if ( !isset($this) ) { return static::resolve4static($response); }

        $this->state = 'fulfilled';

        $this->executecallabe($this->resolves, $response);
    }

    public static function all(array $promises)
    {
        return new self(function($resolve, $reject) use (&$promises){
            $results = [];

            $rejected = false;

            $promisenum = 0;

            foreach ($promises as $key => $callable) {
                $promise = static::callable2promise($callable);

                $results[$key] = null;

                $promisenum++;

                $promise->then(function($response) use (&$promisenum, &$rejected, &$results, $resolve, $key) {
                    if ( $rejected ) { return ; }

                    $results[$key] = $response;

                    if ( !--$promisenum ) { $resolve($results); }
                });

                $promise->catch(function($response) use ($reject, $key) {
                    $rejected = true; $reject($response, $key);
                });
            }
        });
    }

    public static function race(array $promises)
    {
        return new self(function($resolve, $reject) use (&$promises){
            $RACEd = false;

            foreach ($promises as $key => $callable) {
                $promise = static::callable2promise($callable);

                $promise->then(function($response) use ($RACEd, $resolve, $key) {
                    !$RACEd && ($RACEd = true && $resolve($response, $key));
                });

                $promise->catch(function($response) use ($RACEd, $reject, $key) {
                    !$RACEd && ($RACEd = true && $reject($response, $key));
                });
            }
        });
    }

    public static function pipe(...$args)
    {
        $defer = static::defer();

        $pipefn = function(array $callables, $response = null) use ($defer, &$pipefn) {
            $callable = array_shift($callables);

            if ( !$callable ) { return $defer->resolve($response); }

            if ( !is_callable($callable) ) { return $pipefn($callables, $response); }

            static::callable2promise($callable, $response)
                ->then(function($response) use (&$pipefn, &$callables){
                    $pipefn($callables, $response);
                })->catch(function($reason) use ($defer) {
                    $defer->reject($reason);
                });
        };

        $pipefn( static::args2array($args) );

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
            }

            public function reject($response = null)
            {
                return call_user_func($this->rejectproto, $response);
            }

            public function resolve($response = null)
            {
                return call_user_func($this->resolveproto, $response);
            }
        };
    }

    public static function allsettled(array $promises)
    {
        return new self(function($resolve, $reject) use (&$promises){
            $results = [];

            $promisenum = 0;

            foreach ($promises as $key => $callable) {
                $promise = static::callable2promise($callable);

                $results[$key] = new stdClass();

                $promisenum++;

                $promise->then(function($response) use (&$promisenum, &$results, $resolve, $key) {
                    $results[$key]->value = $response;
                    $results[$key]->status = 'fulfilled';
                    if ( !--$promisenum ) { $resolve($results); }
                });

                $promise->catch(function($response) use (&$promisenum, &$results, $resolve, $key) {
                    $results[$key]->reason = $response;
                    $results[$key]->status = 'rejected';
                    if ( !--$promisenum ) { $resolve($results); }
                });
            }
        });
    }

    private function reject4static($response = null)
    {
        return static::state4static(0, $response);
    }

    private function resolve4static($response = null)
    {
        return static::state4static(1, $response);
    }

    private function ispromise($val)
    {
        return is_object($val) && $val instanceof self;
    }

    private function state4static($type, $response = null)
    {
        return new self(function($resolve, $reject)use($type, &$response){
            Co::sleep(0.001); Go($type ? $resolve : $reject, $response);
        });
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

    private function callable2promise(callable $callable, $response = null)
    {
        $rf = new ReflectionFunction($callable);

        $parameters = $rf->getParameters();

        if ( empty($parameters) ) {
            $promise = $callable($response);
        }

        else if ( $parameters[0]->name === 'resolve' ) {
            $promise = new self($callable, $response);
        }

        else {
            $promise = $callable($response);
        }

        return static::ispromise($promise) ? $promise : (
                $promise ? static::resolve($promise) : static::reject($promise)
            );
    }

    private function executecallabe(&$callables, $response = null)
    {
        while ( $callable = array_shift($callables) ) {
            if ( static::ispromise($callable) ) {
                $this->executepromise($callable); break;
            }

            if ( !is_callable($callable) ) { continue; }

            if ( static::ispromise($val = $callable($response)) ) {
                $this->executepromise($val); break;
            }
        }

        array_splice($this->rejects, 0);
        array_splice($this->resolves, 0);
    }

    private function executepromise($promise)
    {
        $promise->then(function($response){
            call_user_func([$this, $this->state === 'rejected' ? 'reject' : 'resolve'], $response);
        });
    }
}
