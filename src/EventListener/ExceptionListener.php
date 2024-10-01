<?php

namespace App\EventListener;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
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
        return preg_match('/^\/(admin|api|jwt)\//i', $request->getRequestUri()) === 1;
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

                break;
            case Response::HTTP_FORBIDDEN:
                $message = 'Forbidden';

                break;
            case Response::HTTP_UNPROCESSABLE_ENTITY:
                if ($exception->getPrevious() instanceof ValidationFailedException) {
                    foreach ($exception->getPrevious()?->getViolations() as $violation) {
                        /** @var ConstraintViolationInterface $violation */
                        if ($violation->getPropertyPath() && $violation->getMessage()) {
                            $errorResponse[$violation->getPropertyPath()]['message'] = $violation->getMessage();
                            $errorResponse[$violation->getPropertyPath()]['code'] = $violation->getCode();
                        }
                    }
                }

                $message = $errorResponse ?? ($exception->getMessage() ? $exception->getMessage() : 'Something gone wrong');

                break;
            default:
                $statusCode = Response::HTTP_INTERNAL_SERVER_ERROR;
                $message = 'Something gone wrong';
        }

        return new JsonResponse($message, $statusCode);
    }
}
