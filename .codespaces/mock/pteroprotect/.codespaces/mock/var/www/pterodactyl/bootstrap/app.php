<?php
return new class {
    public function make($class) {
        return new class {
            public function bootstrap() {}
        };
    }
};
