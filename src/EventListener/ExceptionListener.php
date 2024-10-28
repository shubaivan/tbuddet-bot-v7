<?php

namespace App\EventListener;

use App\Error\ErrorCodeEnum;
use App\Exception\AuthException;
use App\Exception\AuthExceptionInterface;
use App\Exception\InvalidJsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;

class ExceptionListener
{
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        if (!($exception instanceof HttpExceptionInterface)) {
            return;
        }

        $request = $event->getRequest();

        if ($this->isSatisfiedBy($request)) {
            $response = $this->createApiResponse($exception);
            $event->setResponse($response);
        }
    }

    private function isSatisfiedBy(Request $request): bool
    {
        return preg_match('/^\/(api|jwt|admin)\//i', $request->getRequestUri()) === 1;
    }

    private function createApiResponse(HttpExceptionInterface $exception): JsonResponse
    {
        $statusCode = $exception->getStatusCode();

        // todo handle 400 & 415. https://symfony.com/blog/new-in-symfony-6-3-mapping-request-data-to-typed-objects
        switch ($statusCode) {
            case Response::HTTP_NOT_FOUND:
            case Response::HTTP_METHOD_NOT_ALLOWED:
                $message = 'Not found';

                break;
            case Response::HTTP_BAD_REQUEST:
                $message = $exception->getMessage();
                if ($exception instanceof InvalidJsonException) {
                    $message = [
                        'message' => [
                            'message' => 'Malformed request payload',
                            'code' => ErrorCodeEnum::MALFORMED_REQUEST_PAYLOAD_INVALID->value
                        ]
                    ];
                }
                break;
            case Response::HTTP_FORBIDDEN:
                $message = 'Forbidden';

                break;
            case Response::HTTP_UNPROCESSABLE_ENTITY:
                if ($exception->getPrevious() instanceof ValidationFailedException) {
                    foreach ($exception->getPrevious()?->getViolations() as $key=>$violation) {
                        /** @var ConstraintViolationInterface $violation */
                        if ($violation->getPropertyPath() && $violation->getMessage()) {
                            $errorResponse[$violation->getPropertyPath()][$key]['message'] = $violation->getMessage();
                            $errorResponse[$violation->getPropertyPath()][$key]['code'] = $violation->getCode();
                        }
                    }
                }

                if (isset($errorResponse)) {
                    $message['errors'] = array_map(function (array $pp) {
                        return array_values($pp);
                    }, $errorResponse);
                    if ($exception instanceof AuthExceptionInterface) {
                        $message['message']['message'] = $exception->getAuthMessage();
                        $message['message']['code'] = $exception->getAuthCode();
                    }
                } else {
                    $message = $exception->getMessage() ? $exception->getMessage() : 'Something gone wrong';
                }

                break;
            default:
                $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
                $message = 'Something gone wrong';
        }

        return new JsonResponse((is_array($message) ? $message : ['error' => $message]), $statusCode);
    }
}
