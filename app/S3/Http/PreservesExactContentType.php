<?php

declare(strict_types=1);

namespace App\S3\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Keeps Content-Type exactly as the object was stored with.
 *
 * Symfony's Response::prepare() appends "; charset=UTF-8" to any text/*
 * Content-Type that does not already name one. That is right for a web page and
 * wrong for object storage: S3 echoes Content-Type byte for byte, so a client
 * that writes "text/markdown" and reads back "text/markdown; charset=utf-8"
 * sees a different object than the one it stored.
 *
 * The fix has to live on the response rather than in middleware, because
 * prepare() runs in Router::runRoute *after* the route middleware stack has
 * already unwound — anything set in middleware is overwritten a moment later.
 */
trait PreservesExactContentType
{
    public function prepare(Request $request): static
    {
        $contentType = $this->headers->get('Content-Type');

        parent::prepare($request);

        // prepare() legitimately strips the header on 204 and 304; restoring it
        // there would contradict the spec it is enforcing.
        if ($contentType !== null && $this->headers->has('Content-Type')) {
            $this->headers->set('Content-Type', $contentType);
        }

        return $this;
    }
}
