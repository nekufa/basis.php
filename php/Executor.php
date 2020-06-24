<?php

namespace Basis;

use Basis\Procedure\JobQueue\Cleanup;
use Basis\Procedure\JobQueue\Take;
use Basis\Procedure\JobResult\Foreign;
use Exception;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\Procedure;
use Tarantool\Mapper\Procedure\FindOrCreate;
use Tarantool\Mapper\Repository;

class Executor
{
    use Toolkit;

    public function normalize(array $request): array
    {
        if (!array_key_exists('job', $request) || !$request['job']) {
            throw new Exception("No job defined");
        }

        if (!array_key_exists('service', $request) || !$request['service']) {
            if ($this->get(Dispatcher::class)->isLocalJob($request['job'])) {
                $request['service'] = $this->app->getName();
            } else {
                $request['service'] = explode('.', $request['job'])[0];
            }
        }

        if (!array_key_exists('params', $request)) {
            $request['params'] = [];
        } else {
            $request['params'] = $this->get(Converter::class)->toArray($request['params']);
        }

        $context = $this->get(Context::class)->toArray();
        $jobContext = $this->findOrCreate('job_context', [
            'hash' => md5(json_encode($context))
        ], [
            'hash' => md5(json_encode($context)),
            'context' => $context,
        ]);

        $request['context'] = $jobContext->id;

        $request['hash'] = md5(json_encode([
            $request['service'],
            $request['job'],
            $request['params'],
        ]));

        return $request;
    }

    public function initRequest($request)
    {
        $request = $this->normalize($request);
        $request['status'] = 'new';

        $params = [
            'status' => $request['status'],
            'hash' => $request['hash'],
        ];

        return $this->findOrCreate('job_queue', $params, $request);
    }

    public function send(string $job, array $params = [], string $service = null)
    {
        $this->initRequest(compact('job', 'params', 'service'));
    }

    public function dispatch(string $job, array $params = [], string $service = null)
    {
        $recipient = $this->getServiceName();
        $request = compact('job', 'params', 'service', 'recipient');
        $request = $this->normalize($request);

        $result = $this->findOne('job_result', [
            'service' => $this->getServiceName(),
            'hash' => $request['hash'],
        ]);
        if ($result) {
            if ($result->expire && $result->expire < time()) {
                $this->getMapper()->remove($result);
            } else {
                return $result->data;
            }
        }

        $this->initRequest($request);
        return $this->getResult($request['hash']);
    }

    public function process()
    {
        $this->transferResult();
        return $this->processQueue();
    }

    public function processQueue()
    {
        $tuple = $this->get(Take::class)();
        if (!$tuple) {
            return;
        }
        $request = $this->getRepository('job_queue')->getInstance($tuple);

        if ($request->service != $this->getServiceName()) {
            try {
                return $this->transferRequest($request);
            } catch (Exception $e) {
                throw $e;
                // return $this->processRequest($request);
            }
        }

        return $this->processRequest($request);
    }

    protected function transferRequest(Entity $request)
    {
        $context = $request->getContext();
        $remoteContext = $this->findOrCreate("$request->service.job_context", [
            'hash' => $context->hash
        ], [
            'hash' => $context->hash,
            'context' => $context->context,
        ]);

        $template = $this->get(Converter::class)->toArray($request);
        $template['context'] = $remoteContext->id;
        $template['status'] = 'new';
        unset($template['id']);

        $params = [
            'hash' => $template['hash'],
            'status' => $template['status'],
        ];

        $this->findOrCreate("$request->service.job_queue", $params, $template);

        $request->status = 'transfered';
        $request->save();

        return $request;
    }

    public function processRequest($request)
    {
        $context = $this->get(Context::class);
        $backup = $context->toArray();
        $context->reset($request->getContext()->context);

        $result = $this->get(Dispatcher::class)->dispatch($request->job, $request->params, $request->service);

        $context->reset($backup);

        if ($request->recipient) {
            $this->findOrCreate('job_result', [
                'service' => $request->recipient,
                'hash' => $request->hash,
            ], [
                'service' => $request->recipient,
                'hash' => $request->hash,
                'data' => $this->get(Converter::class)->toArray($result),
                'expire' => property_exists($result, 'expire') ? $result->expire : 0,
            ]);
        }

        return $this->getMapper()->remove($request);
    }

    protected function transferResult()
    {
        $remote = $this->get(Foreign::class)($this->getServiceName());
        if (count($remote)) {
            $group = [];
            foreach ($remote as $tuple) {
                $result = $this->getRepository('job_result')->getInstance($tuple);
                if (!array_key_exists($result->service, $group)) {
                    $group[$result->service] = [];
                }
                $group[$result->service][] = $result;
            }
            foreach ($group as $service => $results) {
                foreach ($results as $result) {
                    $this->findOrCreate("$service.job_result", [
                        'service' => $result->service,
                        'hash' => $result->hash,
                    ], [
                        'service' => $result->service,
                        'hash' => $result->hash,
                        'data' => $result->data,
                        'expire' => $result->expire,
                    ]);
                    $this->getMapper()->remove($result);
                }

                $this->getRepository("$result->service.job_queue")
                    ->getMapper()
                    ->getPlugin(Procedure::class)
                    ->get(Cleanup::class)($result->service);
            }
        }
    }

    public function getResult($hash)
    {
        $result = $this->findOne('job_result', [
            'service' => $this->getServiceName(),
            'hash' => $hash,
        ]);

        
        ob_flush();

        if (!$result) {
            if (!$this->processQueue()) {
                $request = $this->findOne('job_queue', [
                    'status' => 'transfered',
                    'hash' => $hash,
                ]);
                if ($request && $request->service) {
                    $r = $this->get(Dispatcher::class)
                        ->dispatch('module.execute', [], $request->service);
                } else {
                    usleep(50000); // 50 milliseconds sleep
                }
            }
            $this->getRepository('job_result')->flushCache();
            return $this->getResult($hash);
        }
        return $this->get(Converter::class)->toObject($result->data);
    }

    public function getServiceName()
    {
        return $this->app->getName();
    }
}