<?php
/**************************************************
* author: 拓荒者 <eMuBin@126.com>
* request: PHP 7.0+
**************************************************/

namespace helper\promise {
    use Co, helper, ReflectionClass;

    define('helper_promise_states', ['pending', 'canceled', 'rejected', 'fulfilled']);

    define('helper_promise_fail_states', array_slice(helper_promise_states, 1, 2));

    define('helper_promise_finally_states', array_slice(helper_promise_states, 1));

    class proto {
        const pending = helper_promise_states[0];

        const canceled = helper_promise_states[1];

        const rejected = helper_promise_states[2];

        const fulfilled = helper_promise_states[3];

        private $id;

        private $state = helper_promise_states[0];

        private $result;

        private $waiting = false;

        private $suspend = false;

        private $rejects = [];

        private $resolves = [];

        private $finallys = [];

        function __construct(callable $fn = null) {
            helper_debug < 0 && helper_emergency(__METHOD__.':'.spl_object_id($this));

            // $traces = debug_backtrace(); var_dump(join(':', [$traces[1]['file'], $traces[1]['line'], $traces[2]['function'], $traces[2]['class'], spl_object_id($this)]));

            Go([$this, '_exec'], $fn);
        }

        function __destruct() {
            helper_debug < 0 && helper_emergency(__METHOD__.':'.spl_object_id($this));
        }

        function __get(string $property) {
            switch ($property) {
                case 'state': return $this->state;

                case 'result': return $this->result;
            }
        }

        public function wait() {
            $this->waiting = true;

            Co::exists($this->id) && Co::join([$this->id]);

            $result = $this->result; $this->result = null;

            return $result;
        }

        public function then(callable $handler) {
            $this->resolves[] = $handler; return $this;
        }

        public function catch(callable $handler) {
            $this->rejects[] = $handler; return $this;
        }

        public function cancel() {
            Co::cancel($this->id);
        }

        public function finally(callable $handler) {
            $this->finallys[] = $handler; return $this;
        }

        public function rejected() {
            return in_array($this->state, helper_promise_fail_states);
        }

        public function finallyed() {
            return in_array($this->state, helper_promise_finally_states);
        }

        private function _exec(callable $fn = null) {
            $this->id = Co::getCid();

            if ( is_null($fn) ) { return Co::suspend(); }

            $rc = new ReflectionClass($this);

            $reject = $rc->getMethod('_reject');

            $resolve = $rc->getMethod('_resolve');

            $fn($resolve->getClosure($this), $reject->getClosure($this));

            $this->_canceled();
        }

        protected function _state(string $state) {
            $this->state = $state;
        }

        protected function _result(string $state, $result = null) {
            if ( $this->finallyed() ) { return ; }

            $this->state = $state;

            $this->result = $result;

            $this->_handler();
        }

        protected function _reject($result = null) {
            $this->_result(self::rejected, $result);
        }

        protected function _resolve($result = null) {
            $this->_result(self::fulfilled, $result);
        }

        protected function _handler() {
            $handlers = &$this->{$this->state === self::fulfilled ? 'resolves' : 'rejects'};

            while ($handler = array_shift($handlers)) { $handler($this->result); }

            while ($handler = array_shift($this->finallys)) { $handler($this->result); }

            array_splice($this->rejects, 0);

            array_splice($this->resolves, 0);

            array_splice($this->finallys, 0);

            is_yield($this->id) && Co::resume($this->id);

            if ( !$this->waiting ) { $this->result = null; $this->waiting = false; }

            $this->suspend = false;

            return $this;
        }

        protected function _canceled() {
            if ( !$this->finallyed() && Co::isCanceled() ) {
                $this->state = $this->result = self::canceled;
            }
        }
    }

    /**
     * 解忧器
     * @readme 更自由的调用 promise
     */
    class resolvers extends proto {
        public function reject($result = null) {
            return $this->_reject($result);
        }

        public function resolve($result = null) {
            return $this->_resolve($result);
        }
    }

    /**
     * 限制器
     * @readme 限制 promise 的并发数量
     */
    class restrictor {
        private $id;

        private $make;

        private $current = 0;

        private $capacity = 0;

        private $resolver;

        private $finallyed = false;

        function __construct(int $capacity, callable $make) {
            $this->id = Co::getCid();

            $this->make = $make;

            $this->capacity = $capacity;

            $this->resolver = new resolvers();

            $this->dispatch();
        }

        public function add(): bool {
            $promise = call_user_func($this->make);

            if ( !($this->finallyed = !is_promise($promise)) ) {
                $this->current++; $promise->finally([$this, 'defer']);
            }

            return !$this->finallyed;
        }

        public function wait() {
            return $this->resolver->wait();
        }

        public function defer() {
            $this->current--;

            !$this->finallyed ? Co::resume($this->id) : (
                $this->current < 1 && $this->resolver->resolve()
            );
        }

        public function dispatch() {
            do {
                while ($this->current < $this->capacity && $this->add()) {}
            } while (!$this->finallyed && Co::suspend());
        }
    }
}

namespace helper {
    use helper\promise\{proto, resolvers, restrictor};

    class promise extends proto {
        static public function try(callable $fn, ...$args) {
            return new proto(static function($resolve, $reject) use ($fn, $args) {
                try {
                    $result = helper_call_user_func($fn, ...$args);

                    if ( !($result instanceof self) ) { return $resolve($result); }

                    $result->wait(); return $result->state === proto::fulfilled ? $resolve($result->result) : $reject($result->result);
                } catch (Exception $e) {
                    $reject($e);
                }
            });
        }

        static public function all(array $promises, bool $settled = false) {
            return new proto(static function($resolve, $reject) use (&$promises, $settled) {
                $sizeof = sizeof($promises);

                $results = array_fill(0, $sizeof, null);

                $finallyed = false;

                foreach ($promises as $i => $it) {
                    switch($it->state) {
                        case proto::canceled:
                        case proto::rejected:
                            return $reject($it->result);

                        case proto::fulfilled:
                            $results[$i] = $it->result; --$sizeof; break;

                        default:
                            $it->finally(function($result = null) use (&$finallyed, &$results, &$sizeof, $settled, $resolve, $reject, $it, $i) {
                                if ( $finallyed ) { return ; }

                                if ( !$settled && $it->rejected() ) {
                                    $finallyed = true; return $reject($result);
                                }

                                $results[$i] = $result;

                                if ( --$sizeof < 1 ) { $resolve($results); }
                            });
                    }
                }
            });
        }

        static public function any(array $promises) { return self::race($promises); }

        static public function race(array $promises) {
            return new proto(static function($resolve, $reject) use (&$promises) {
                $finallyed = false;

                foreach ($promises as $it) {
                    if ( $it->finallyed() ) {
                        return $it->rejected() ? $reject($it->result) : $resolve($it->result);
                    }

                    $it->finally(function($result = null) use (&$finallyed, $promises, $resolve, $reject, $it) {
                        if ( $finallyed ?: !($finallyed = true) ) { return ; }

                        $it->rejected() ? $reject($result) : $resolve($result);
                    });
                }
            });
        }

        static public function pipe(...$args) {
            if ( empty($args) ) { return ; }

            $result = !is_promise($args[0]) && !is_callable($args[0]) ? array_shift($args) : null;

            foreach ($args as $arg) {
                if ( is_promise($arg) ) { $result = $arg->wait(); }

                else if ( is_callable($arg) ) {
                    is_promise($result = $arg($result)) && ($result = $result->wait());
                }
            }
        }

        static public function reject($result = null) { return (new resolvers())->reject($result); }

        static public function resolve($result = null) { return (new resolvers())->resolve($result); }

        static public function resolvers() { return new resolvers(); }

        static public function restrictor(int $capacity, callable $make) { return new restrictor($capacity, $make); }

        static public function allsettled(array $promises) { return self::all($promises, true); }

        static public function withResolvers() { return self::resolvers(); }
    }
}

namespace {
    function is_yield(int $id) {
        return is_array($traces = Co::getBackTrace($id)) && in_array(($first = array_shift($traces))['function'], ['yield', 'suspend']) &&
            $first['class'] === 'Swoole\Coroutine';
    }

    function is_promise($any = null): bool {
        return is_object($any) && $any instanceof helper\promise\proto;
    }

    function is_resolvers($any = null): bool {
        return is_object($any) && $any instanceof helper\promise\resolvers;
    }

    function is_restrictor($any = null): bool {
        return is_object($any) && $any instanceof helper\promise\restrictor;
    }
}
