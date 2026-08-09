<?php

declare(strict_types=1);

namespace SCS\Controller;

use SCS\Exception\ConflictException;
use SCS\Exception\ForbiddenException;
use SCS\Exception\NotFoundException;
use SCS\Exception\TooManyRequestsException;
use SCS\Exception\UnauthorizedException;
use SCS\Exception\ValidationException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

abstract class RestController
{
    public function __construct(protected readonly ValidatorInterface $validator)
    {
    }

    protected function validate(object $dto): void
    {
        $violations = $this->validator->validate($dto);
        if ($violations->count() === 0) {
            return;
        }

        $errors = [];
        foreach ($violations as $violation) {
            $errors[$violation->getPropertyPath()] = (string)$violation->getMessage();
        }

        throw new ValidationException($errors);
    }

    protected function handle(callable $action): \WP_REST_Response
    {
        try {
            return $action();
        } catch (NotFoundException $e) {
            return $this->error($e->getMessage(), \WP_Http::NOT_FOUND);
        } catch (UnauthorizedException $e) {
            return $this->error($e->getMessage(), \WP_Http::UNAUTHORIZED);
        } catch (ForbiddenException $e) {
            return $this->error($e->getMessage(), \WP_Http::FORBIDDEN);
        } catch (ConflictException $e) {
            return $this->error($e->getMessage(), \WP_Http::CONFLICT);
        } catch (TooManyRequestsException $e) {
            return $this->error($e->getMessage(), \WP_Http::TOO_MANY_REQUESTS);
        } catch (ValidationException $e) {
            return new \WP_REST_Response(['errors' => $e->getErrors()], \WP_Http::UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            // Keeps internals (SQL, table names, file paths) out of the response even with WP_DEBUG_DISPLAY on.
            error_log(sprintf('[SCS] Unhandled %s: %s', get_class($e), $e->getMessage()));

            return $this->error('An unexpected error occurred.', \WP_Http::INTERNAL_SERVER_ERROR);
        }
    }

    protected function ok(mixed $data): \WP_REST_Response
    {
        return new \WP_REST_Response($data, \WP_Http::OK);
    }

    protected function created(mixed $data): \WP_REST_Response
    {
        return new \WP_REST_Response($data, \WP_Http::CREATED);
    }

    protected function noContent(): \WP_REST_Response
    {
        return new \WP_REST_Response(null, \WP_Http::NO_CONTENT);
    }

    protected function error(string $message, int $status): \WP_REST_Response
    {
        return new \WP_REST_Response(['error' => $message], $status);
    }
}
