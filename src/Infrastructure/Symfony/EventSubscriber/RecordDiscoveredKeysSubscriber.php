<?php

declare(strict_types=1);

namespace Eekes\SuluTranslationsBundle\Infrastructure\Symfony\EventSubscriber;

use Eekes\SuluTranslationsBundle\Application\Recorder\MissingMessageRecorder;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes what the request discovered, after the response has been sent.
 *
 * `kernel.terminate` and not `kernel.response`: recording keys is a convenience, and a visitor
 * should never wait for it, nor see it fail. The recorder catches its own exceptions for the
 * same reason.
 */
class RecordDiscoveredKeysSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly MissingMessageRecorder $recorder)
    {
    }

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        $events = [KernelEvents::TERMINATE => 'onTerminate'];

        // A console command renders templates too - a mail, a PDF, a sitemap - and those keys
        // are as real as the ones a page asks for.
        if (\class_exists(ConsoleEvents::class)) {
            $events[ConsoleEvents::TERMINATE] = 'onTerminate';
        }

        return $events;
    }

    public function onTerminate(): void
    {
        $this->recorder->flush();
    }
}
