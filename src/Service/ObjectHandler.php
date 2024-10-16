<?php

namespace App\Service;

use App\Exception\Enum\AuthExceptionEnum;
use App\Validator\MatchId;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\PartialDenormalizationException;
use Symfony\Component\Serializer\Exception\UnsupportedFormatException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class ObjectHandler
{
    public function __construct(
        private SerializerInterface $serializer,
        private ValidatorInterface $validator
    ) {}

    public function handleRequestByObject(
        AuthExceptionEnum $authExceptionEnum,
        string $typeDenormalize,
        Request $request
    ): mixed {
        try {
            $violations = new ConstraintViolationList();

            if (($format = $request->getContentTypeFormat()) === null) {
                throw new HttpException(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'Unsupported format');
            }

            if ($data = $request->request->all()) {
                return $this->serializer->denormalize($data, $typeDenormalize, null, [
                    'collect_denormalization_errors' => true,
                ]);
            }

            if (($data = $request->getContent()) === '') {
                throw new HttpException(Response::HTTP_BAD_REQUEST, 'Request payload is empty');
            }

            if ($format !== 'json') {
                throw new HttpException(Response::HTTP_BAD_REQUEST, 'Request payload contains invalid "form" data');
            }

            try {
                $payload = $this->serializer->deserialize($data, $typeDenormalize, $format, [
                    'collect_denormalization_errors' => true,
                ]);
            } catch (UnsupportedFormatException $e) {
                throw new HttpException(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, sprintf('Unsupported format: "%s"', $format), $e);
            } catch (NotEncodableValueException $e) {
                throw new HttpException(Response::HTTP_BAD_REQUEST, sprintf('Request payload contains invalid "%s" data', $format), $e);
            }
        } catch (PartialDenormalizationException $e) {
            $transFunction = fn($m, $p) => strtr($m, $p);

            foreach ($e->getErrors() as $error) {
                $parameters = ['{{ type }}' => implode('|', $error->getExpectedTypes())];

                if ($error->canUseMessageForUser()) {
                    $parameters['hint'] = $error->getMessage();
                }

                $template = 'This value should be of type {{ type }}';
                $message = $transFunction($template, $parameters, 'validators');
                $violations->add(new ConstraintViolation($message, $template, $parameters, null, $error->getPath(), null));
            }

            $payload = $e->getData();
        }

        if ($payload === null) {
            throw new HttpException(Response::HTTP_NOT_FOUND, 'This user identifier is not valid');
        }

        $violations->addAll($this->validator->validate($payload));
        if (\count($violations)) {
            throw new $authExceptionEnum->value(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                implode("\n", array_map(
                    static fn($e) => $e->getMessage(),
                    iterator_to_array($violations))),
                new ValidationFailedException($payload, $violations)
            );
        }

        return $payload;
    }

    public function entityLookup(string $identifier, string $actualClass, string $property, array $extraCriteria = [])
    {
        if (!class_exists($actualClass)) {
            throw new \Exception('Class does not exist');
        }

        $matchId = new MatchId(
            actualClass: $actualClass,
            property: $property
        );
        $violations = new ConstraintViolationList();

        $violations->addAll($this->validator->validate($identifier, $matchId));
        if (\count($violations)) {
            throw new HttpException(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                implode("\n", array_map(
                    static fn($e) => $e->getMessage(),
                    iterator_to_array($violations))),
                new ValidationFailedException($identifier, $violations)
            );
        }
    }
}
