<?php

namespace App\Console\Commands;

use App\Mail\MailTemplateRegistry;
use App\Services\Branding\BrandingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Send transactional mail to a real address, to check it arrives.
 *
 * The admin panel can preview a template, which answers "does it render". This
 * answers the different and harder question: does it land in an inbox. Those
 * come apart constantly — a template that renders perfectly still goes to spam
 * when SPF, DKIM and DMARC are not all passing, and the only way to know is to
 * send to Gmail, Yahoo and Outlook and read the headers each one adds.
 *
 * So this is a deployment tool, not a debugging one. It sends synchronously,
 * bypassing the queue: a queued send that silently fails in a worker tells you
 * nothing, and the whole point here is to see the error.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test
        {address? : Where to send. Use a real Gmail, Yahoo or Outlook address}
        {--template= : One template key. Omit to send every transactional template}
        {--list : Show the template keys and send nothing}';

    protected $description = 'Send transactional mail to a real address and report what happened';

    public function handle(MailTemplateRegistry $registry, BrandingService $branding): int
    {
        if ($this->option('list')) {
            $this->table(
                ['Key', 'Name', 'Transactional'],
                collect($registry->all())
                    ->map(fn (array $t): array => [$t['key'], $t['name'], $t['transactional'] ? 'yes' : 'no'])
                    ->all(),
            );

            return self::SUCCESS;
        }

        $address = (string) $this->argument('address');

        if ($address === '') {
            $this->error('Who should this go to? Pass an address, or --list to see the templates.');

            return self::FAILURE;
        }

        if (! filter_var($address, FILTER_VALIDATE_EMAIL)) {
            $this->error("{$address} is not an email address.");

            return self::FAILURE;
        }

        $templates = collect($registry->all())
            ->when($this->option('template'), fn ($all, $key) => $all->where('key', $key))
            ->values();

        if ($templates->isEmpty()) {
            $this->error('No template matches --template='.$this->option('template').'. Try --list.');

            return self::FAILURE;
        }

        $this->line("Sending as <info>{$branding->name()}</info> via <info>".config('mail.default')."</info>");
        $this->newLine();

        $rows = [];
        $failures = 0;

        foreach ($templates as $template) {
            $mailable = $registry->sample($template['key']);

            if ($mailable === null) {
                $rows[] = [$template['key'], '—', 'no sample available'];

                continue;
            }

            try {
                /*
                 * Sent rather than queued, on purpose. A queued send hands the
                 * message to a worker and reports success; if the provider
                 * rejects it, that happens somewhere else, later, invisibly.
                 * This command exists to surface exactly that rejection.
                 */
                Mail::to($address)->sendNow($mailable);

                $rows[] = [$template['key'], $mailable->envelope()->subject ?? '—', 'sent'];
            } catch (Throwable $exception) {
                $failures++;
                $rows[] = [$template['key'], '—', 'FAILED: '.$exception->getMessage()];
            }
        }

        $this->table(['Template', 'Subject', 'Result'], $rows);

        if ($failures > 0) {
            $this->error("{$failures} failed to send.");

            return self::FAILURE;
        }

        $this->info('All sent. Now open the raw headers on the receiving side.');
        $this->line('  Authentication-Results must show <info>spf=pass</info>, <info>dkim=pass</info> and <info>dmarc=pass</info>.');
        $this->line('  Anything less and this mail reaches an inbox today and a spam folder next month.');

        return self::SUCCESS;
    }
}
