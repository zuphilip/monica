<?php

namespace App\Console\Commands\OneTime;

use App\Events\MoveAvatarEvent;
use App\Models\Contact\Contact;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Event;
use Illuminate\Console\ConfirmableTrait;
use App\Exceptions\FileNotFoundException;
use Symfony\Component\Console\Output\OutputInterface;
use App\Jobs\Avatars\MoveContactAvatarToPhotosDirectory;

/**
 * This command moves current avatars to the new Photos directory and converts
 * each avatar to a Photo object.
 */
class MoveAvatarsToPhotosDirectory extends Command
{
    use ConfirmableTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monica:moveavatarstophotosdirectory
                            {--force : Force the operation to run when in production.}
                            {--dryrun : Simulate the execution but not write anything.}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move avatars to the Photos directory, and create a photo object for each one of them';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        if (! $this->confirmToProceed()) {
            return;
        }

        Event::listen(MoveAvatarEvent::class, function ($event) {
            $this->handleEvent($event->contact);
        });

        $delay = now();

        Contact::where('has_avatar', true)
            ->chunk(200, function ($contacts) use ($delay) {
                foreach ($contacts as $contact) {
                    $this->handleContact($contact, $delay);
                }
                // add some delay, so we treat 200 contacts each 10 minutes
                $delay = $delay->addMinutes(10);
            });
    }

    private function handleContact($contact, $delay)
    {
        try {
            if ($this->option('dryrun')) {
                MoveContactAvatarToPhotosDirectory::dispatchNow($contact, true);
            } else {
                MoveContactAvatarToPhotosDirectory::dispatch($contact, false)
                    ->delay($delay);
            }
        } catch (FileNotFoundException $e) {
            if ($this->getOutput()->getVerbosity() >= OutputInterface::VERBOSITY_VERBOSE) {
                $this->warn('  ! File not found: '.$e->fileName);
            }
        }
    }

    private function handleEvent($contact)
    {
        $this->info('Contact id:'.$contact->id.' | Avatar location:'.$contact->avatar_location.' | File name:'.$contact->avatar_file_name);
    }
}
