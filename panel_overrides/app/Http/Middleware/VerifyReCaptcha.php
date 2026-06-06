<?php

namespace Pterodactyl\Http\Middleware;

use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pterodactyl\Events\Auth\FailedCaptcha;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Symfony\Component\HttpKernel\Exception\HttpException;

class VerifyReCaptcha
{
    public function __construct(private Dispatcher $dispatcher, private Repository $config)
    {
    }

    public function handle(Request $request, \Closure $next): mixed
    {
        if (!$this->config->get('recaptcha.enabled')) {
            return $next($request);
        }

        if ($request->filled('g-recaptcha-response')) {
            try {
                $client = new Client([
                    'connect_timeout' => 2.0,
                    'timeout' => 5.0,
                ]);
                $res = $client->post($this->config->get('recaptcha.domain'), [
                    'connect_timeout' => 2.0,
                    'timeout' => 5.0,
                    'form_params' => [
                        'secret' => $this->config->get('recaptcha.secret_key'),
                        'response' => $request->input('g-recaptcha-response'),
                    ],
                ]);
            } catch (\Throwable $exception) {
                throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'reCAPTCHA verification service unavailable.', $exception);
            }

            if ($res && $res->getStatusCode() === 200) {
                $result = json_decode($res->getBody());

                if (is_object($result) && !empty($result->success) && (!$this->config->get('recaptcha.verify_domain') || $this->isResponseVerified($result, $request))) {
                    return $next($request);
                }
            }
        }

        $this->dispatcher->dispatch(new FailedCaptcha($request->ip(), $request->userAgent()));

        throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY, 'reCAPTCHA verification failed.');
    }

    private function isResponseVerified(\stdClass $result, Request $request): bool
    {
        if (!$this->config->get('recaptcha.verify_domain')) {
            return false;
        }

        $url = parse_url($request->url());

        return ($result->hostname ?? null) === ($url['host'] ?? null);
    }
}
