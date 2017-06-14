<?php

namespace Basis;

use LogicException;
use League\Container\Container;
use ReflectionClass;
use ReflectionProperty;

class Runner
{
    private $app;
    private $mapping;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function getMapping()
    {
        if (!$this->mapping) {
            $jobs = [];
            foreach ($this->app->get(Framework::class)->listClasses('Job') as $class) {
                $jobs[substr($class, strlen('Basis\\Job\\'))] = $class;
            }
            $serviceName = ucfirst($this->app->get(Config::class)['service']);
            foreach ($this->app->get(Filesystem::class)->listClasses('Job') as $class) {
                $jobs[$serviceName.'\\'.substr($class, strlen('Job\\'))] = $class;
                $jobs[substr($class, strlen('Job\\'))] = $class;
            }


            foreach ($jobs as $part => $class) {
                $nick = str_replace('\\', '.', $part);
                $jobs[$nick] = $class;
                $jobs[strtolower($nick)] = $class;
            }

            $this->mapping = $jobs;
        }

        return $this->mapping;
    }

    public function getJobClass($nick)
    {
        $mapping = $this->getMapping();
        if (!array_key_exists($nick, $mapping)) {
            throw new LogicException("No job $nick");
        }

        $class = $mapping[$nick];

        if (!class_exists($class)) {
            throw new LogicException("No class for job $nick");
        }
        return $class;
    }

    public function dispatch($nick, $arguments = [])
    {
        $class = $this->getJobClass($nick);

        $instance = $this->app->get($class);
        if (array_key_exists(0, $arguments)) {
            $arguments = $this->castArguments($class, $arguments);
        }

        foreach ($arguments as $k => $v) {
            $instance->$k = $v;
        }

        return $this->app->call([$instance, 'run']);
    }

    private function castArguments($class, $arguments)
    {
        $reflection = new ReflectionClass($class);
        $properties = $reflection->getProperties(ReflectionProperty::IS_PUBLIC);
        if (count($properties) == 1) {
            return [
                $properties[0]->getName() => count($arguments) == 1
                    ? $arguments[0]
                    : implode(' ', $arguments)
            ];
        }
        return $arguments;
    }
}
