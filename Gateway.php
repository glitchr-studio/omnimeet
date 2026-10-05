<?php

namespace Omnimeet;

use Omnimeet\Action\ActionInterface;
use Omnimeet\Exception\NotSupportedException;
use Omnimeet\Exception\ProviderException;
use Omnimeet\Model\Access;
use Omnimeet\Model\Capabilities;
use Omnimeet\Model\Meeting;
use Omnimeet\Model\Notification;
use Omnimeet\Model\Participant;

/** A gateway's actions behind one door: the first action supporting a request answers it. */
final class Gateway implements GatewayInterface
{
    /** @param ActionInterface[] $actions */
    public function __construct(
        private readonly string $name,
        private readonly string $title,
        private readonly Capabilities $capabilities,
        private readonly array $actions,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function supports(string $request): bool
    {
        $probe = (new \ReflectionClass($request))->newInstanceWithoutConstructor();
        foreach ($this->actions as $action) {
            if ($action->supports($probe)) {
                return true;
            }
        }

        return false;
    }

    public function execute(Request\Request $request): Request\Request
    {
        foreach ($this->actions as $action) {
            if ($action->supports($request)) {
                $action->execute($request);
                if (!$request->isAnswered()) {
                    throw new ProviderException($this->name, \sprintf('%s left the request unanswered.', $action::class));
                }

                return $request;
            }
        }

        throw NotSupportedException::for($request, $this->name);
    }

    public function open(Meeting $meeting): Meeting
    {
        return $this->execute(new Request\Open($meeting))->getMeeting();
    }

    public function join(Meeting $meeting, Participant $participant): Access
    {
        return $this->execute(new Request\Join($meeting, $participant))->getAccess();
    }

    public function close(Meeting $meeting): Meeting
    {
        return $this->execute(new Request\Close($meeting))->getMeeting();
    }

    public function fetch(Meeting $meeting): Meeting
    {
        return $this->execute(new Request\Fetch($meeting))->getMeeting();
    }

    public function notify(string $body, array $headers = []): Notification
    {
        return $this->execute(new Request\Notify($body, $headers))->getNotification();
    }
}
