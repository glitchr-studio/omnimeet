<?php

namespace Omnimeet\Action;

/** An action that works through the gateway's own client: a provider's API, a signer, a store. */
interface ApiAwareInterface
{
    public function setApi(object $api): void;
}
