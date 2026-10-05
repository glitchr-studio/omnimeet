<?php

namespace Omnimeet\Action;

use Omnimeet\Request\Request;

/** One thing a gateway does: answers the requests it supports. */
interface ActionInterface
{
    public function supports(Request $request): bool;

    /** Sets the request's result, or throws an Omnimeet\Exception\OmnimeetException. */
    public function execute(Request $request): void;
}
