<?php
/**************************************************
* author: 拓荒者 <eMuBin@126.com>
**************************************************/


class Promise
{
    private $state = 'pending';
    private $rejects = [];
    private $resolves = [];

    function __construct(callable $callable)
    {
        Go($callable, [$this, 'resolve'], [$this, 'reject']);
    }

    public function then($callable)
    {
        $this->resolves[] = $callable; return $this;
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

    public function defer()
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

    public function reject4static($response = null)
    {
        return static::state4static(0, $response);
    }

    public function resolve4static($response = null)
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
    }

    private function executepromise($promise)
    {
        $promise->then(function($response){
            call_user_func([$this, $this->state === 'rejected' ? 'reject' : 'resolve'], $response);
        });
    }
}
