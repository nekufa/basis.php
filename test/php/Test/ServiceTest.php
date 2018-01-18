<?php

namespace Test;

use Basis\Test;
use Basis\Service;
use Tarantool\Mapper\Pool;

class ServiceTest extends Test
{
    public function testBootstrap()
    {
        $context = (object) [
            'routes' => [],
        ];

        $this->mock('web.services')->willReturn([
            'services' => []
        ]);

        $this->mock('event.subscribe')->willReturn([
            'success' => true
        ]);

        $this->mock('web.register')->willDo(function($params) use ($context) {
            foreach ($params['routes'] as $route) {
                $context->routes[] = (object) ['route' => $route, 'service' => $params['service']];
            }
        });

        // internaly it should analyze routes and call web.register
        // then context will be updated and we should validate it
        $this->dispatch('module.register');

        $service = $this->app->get(Service::class);
        $serviceRoutes = [];
        foreach ($context->routes as $route) {
            if ($route->service == $service->getName()) {
                $serviceRoutes[] = $route->route;
            }
        }

        $this->assertCount(2, $serviceRoutes);
        $this->assertContains('index/index', $serviceRoutes);
        $this->assertContains('dynamic/*', $serviceRoutes);
    }

    public function testName()
    {
        $this->assertSame('test', $this->get(Service::class)->getName());
    }

    public function testServices()
    {
        $service = $this->get(Service::class);

        $mock = $this->mock('web.services')->willReturn([
            'services' => ['gateway', 'audit']
        ]);

        $this->assertSame($service->listServices(), ['gateway', 'audit']);
        $this->assertSame($service->listServices(), ['gateway', 'audit']);

        // service list is cached
        $this->assertSame(1, $mock->calls);
    }

    public function testEvents()
    {
        $service = $this->get(Service::class);

        $this->mock('web.services')->willReturn(['services' => ['web']]);

        $this->mock('event.subscribe')->willReturn(['success' => true]);

        $this->get(Pool::class)->registerResolver(function($name) {
            if ($name == 'event') {
                return new class {
                    public function find() {
                        return [
                            (object) ['nick' => 'test.*.*'],
                            (object) ['nick' => 'web.service.updated'],
                        ];
                    }
                };
            }
        });

        $service->subscribe('test.*.*');

        // equals
        $this->assertTrue($service->eventExists('web.service.updated'));
        $this->assertFalse($service->eventExists('guard.session.created'));

        // wildcard
        $this->assertTrue($service->eventExists('test.post.updated'));

        // wildcard
        $this->assertTrue($service->eventMatch('test.post.created', '*.post.*'));
        $this->assertFalse($service->eventMatch('test.post.created', '*.posts.*'));
    }
}
