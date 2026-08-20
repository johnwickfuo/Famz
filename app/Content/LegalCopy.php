<?php

namespace App\Content;

use App\Support\Money;

/**
 * The terms and the privacy notice.
 *
 * Two rules govern everything in this file.
 *
 * No literal company name, anywhere. Every mention is `{company}`, expanded
 * from BrandingService when the page renders, so that renaming the platform in
 * settings renames it in the terms people have agreed to rather than leaving a
 * document naming a company that no longer exists.
 *
 * No hard-coded figures either. The commission rate, the escrow window, the
 * quote validity and the study fee are all settings an administrator can
 * change, and terms of service that quote a stale 5% while the platform
 * actually charges 8% are worse than terms that quote nothing. Every number
 * below is read at render time from the same setting the code charges on.
 *
 * The tone is deliberately plain. These are read — when they are read at all —
 * by somebody deciding whether to trust the platform with money, usually on a
 * phone. Anything that needs a lawyer to parse has failed at the only job it
 * has.
 */
class LegalCopy
{
    /**
     * @return array<string, mixed>
     */
    public function terms(): array
    {
        return [
            'title' => __('Terms of service'),
            'intro' => __('These are the rules for using {company}. Plain language, because you should be able to read them without a lawyer. If something here is unclear, ask us before you rely on it.'),
            'updated' => __('Last updated :date', ['date' => now()->format('F Y')]),
            'sections' => [
                $this->whatWeAre(),
                $this->marketplaceTerms(),
                $this->courseTerms(),
                $this->mentorshipTerms(),
                $this->consultationTerms(),
                $this->quotationTerms(),
                $this->jobsTerms(),
                $this->assistantTerms(),
                $this->accountTerms(),
                $this->changesAndDisputes(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function whatWeAre(): array
    {
        return [
            'heading' => __('What {company} is'),
            'body' => [
                __('{company} runs a marketplace, a training academy, a mentorship service, paid consultations, a farm setup quotation service, a jobs board and a free farming assistant.'),
                __('For some of these we are the seller — courses and consultations are ours. For others we are the place where two other people meet, and that difference decides who is responsible when something goes wrong. Each section below says plainly which one it is.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marketplaceTerms(): array
    {
        $commission = (float) settings('marketplace_commission_percent', 5);
        $escrowDays = (int) settings('escrow_auto_release_days', 7);
        $disputeDays = (int) settings('dispute_window_days', 7);

        return [
            'heading' => __('Buying and selling'),
            'body' => [
                __('Sellers on {company} are independent businesses. They own what they list, they set their prices, and they are responsible for what they send you. {company} is not the seller and does not own the goods.'),
                __('{company} charges sellers a commission of :percent% on each sale. Buyers pay the listed price — the commission comes out of the seller\'s side, not on top of yours.', [
                    'percent' => $this->number($commission),
                ]),
                __('Money you pay is held until the order is delivered. It is released to the seller :days days after they mark it delivered, unless you raise a dispute first.', [
                    'days' => $escrowDays,
                ]),
                __('You have :days days from delivery to raise a dispute. While a dispute is open the money for that order is frozen and cannot be withdrawn by anybody.', [
                    'days' => $disputeDays,
                ]),
                __('Live animals and perishable goods carry their own risks. Read the listing carefully and inspect on delivery — these are the items where a dispute raised a week later is hardest for anybody to settle fairly.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function courseTerms(): array
    {
        return [
            'heading' => __('Training courses'),
            'body' => [
                __('Courses are written and sold by {company}. When you buy one, you are buying from us.'),
                /*
                 * The all-sales-final rule the brief requires, stated plainly
                 * and with its reason. A refund policy that is only a rule
                 * reads as mean; the same rule with its reason reads as fair.
                 */
                __('COURSE SALES ARE FINAL. There are no refunds once you have access, because access is the whole product — the moment you can open the lessons you have received everything you paid for, and we have no way to take it back.'),
                __('Before you buy, the course page tells you what is covered, how long it takes and what you need. Read it. If you are not sure a course is right for you, ask us first — we would rather answer a question than take money for the wrong thing.'),
                __('Your access does not expire. Course content may be updated, added to or corrected over time, and you get those updates at no extra cost.'),
                __('Course material is for you alone. Sharing your login, downloading lessons for redistribution, or reselling the material ends your access without a refund. Lesson files carry a watermark identifying the account that opened them.'),
                __('A certificate from {company} says you completed our course. It is not a government qualification and we do not present it as one.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mentorshipTerms(): array
    {
        $commission = (float) settings('mentorship_commission_percent', 15);
        $window = (int) settings('mentorship_dispute_window_days', 7);

        return [
            'heading' => __('Mentorship'),
            'body' => [
                __('Mentors are independent people we have invited and checked. They are not employees of {company}, and the advice they give is theirs.'),
                __('{company} takes :percent% of what you pay for a mentorship package. The rest goes to the mentor.', [
                    'percent' => $this->number($commission),
                ]),
                __('Contact details are exchanged when a session is booked and paid for, not before. This protects both sides: mentors are not cold-called, and you are not paying for a phone number you could have found anyway.'),
                __('If a mentor does not deliver what was agreed, raise it within :days days of the session and we will look at it.', [
                    'days' => $window,
                ]),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function consultationTerms(): array
    {
        return [
            'heading' => __('Consultations'),
            'body' => [
                __('A consultation is with {company} directly. You do not pick a person — we assign whoever knows your problem best.'),
                __('You describe the problem first and we quote a price. You are under no obligation until you pay, and nothing is charged before you do.'),
                __('An urgent consultation costs more and carries a response time stated at booking, counted in working hours. If we miss it, tell us.'),
                __('Advice given in a consultation is based on what you tell us and the photographs you send. It is professional guidance, not a veterinary diagnosis. Anything involving sick or dying animals needs a vet who can see them.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function quotationTerms(): array
    {
        $validity = (int) settings('quote_validity_days', 30);
        $studyFee = (int) settings('quotation_study_fee', 5_000_000);

        return [
            'heading' => __('Farm setup quotations'),
            'body' => [
                __('A farm setup quotation is a written proposal prepared by hand for your specific site. It is not generated by a calculator.'),
                __('There is a study fee of :fee, paid before we start. It covers the work of studying your site, your budget and what you want to build.', [
                    'fee' => Money::fromKobo($studyFee),
                ]),
                /*
                 * The credit rule, stated in the terms because it is the whole
                 * reason the study fee is acceptable to a client. It is also
                 * tracked as a credit_status on every request, so this sentence
                 * matches a column somebody can be shown.
                 */
                __('IF YOU GO AHEAD WITH THE PROJECT, THE STUDY FEE IS CREDITED IN FULL against the project cost. You do not pay it twice. If you do not go ahead, the fee is not refunded — the study was done and it is yours to keep and take elsewhere.'),
                __('A quotation is valid for :days days from the date we send it. After that, prices may have moved and we will need to reissue it.', [
                    'days' => $validity,
                ]),
                __('Accepting a quotation happens offline, between you and us, by signature or written confirmation. {company} does not take project payments through this website.'),
                __('The proposal document is yours. Take it to a bank, a partner or another contractor if you wish.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobsTerms(): array
    {
        return [
            'heading' => __('The jobs board'),
            'body' => [
                /*
                 * The not-the-employer rule the brief requires. Stated first,
                 * in capitals, because it is the sentence that matters if
                 * anybody is ever hurt or unpaid on a farm they found here.
                 */
                __('{company} IS NOT THE EMPLOYER. We do not place anybody, we do not pay wages, we do not check farms, and we are not party to any employment relationship formed through this board.'),
                __('The jobs board is free. No money passes through {company} for it, in either direction.'),
                __('Farms are responsible for what they advertise and for how they treat the people they hire. Workers are responsible for what they claim about themselves. We do not verify either.'),
                __('Wages, hours, conditions, accommodation and safety are agreed between the farm and the worker directly. If something goes wrong, that is between you and them — but tell us anyway, because we can stop a listing.'),
                __('A worker\'s phone number is shown only to registered employers, is rate-limited per day, and every disclosure is logged. Collecting numbers to build a list is not permitted and gets an account closed.'),
                __('Meet somewhere public first. Do not pay anybody a fee to be considered for work — no legitimate farm on this board will ask for one.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assistantTerms(): array
    {
        return [
            'heading' => __('The farming assistant'),
            'body' => [
                __('The assistant is free and answers automatically. It gives general farming guidance, not professional advice for your particular farm.'),
                __('Figures it quotes — feed amounts, weights, prices — come from published feeding tables and from what sellers are actually listing on this marketplace, and it states the source. It is built not to invent numbers, and to say plainly when it does not have one.'),
                __('IT WILL NOT GIVE DRUG NAMES, DOSAGES OR TREATMENT SCHEDULES, and you should not try to get it to. Anything affecting animal health needs a vet who has seen the animals.'),
                __('Do not make a significant purchase or treat sick animals on the strength of an automated answer. Book a consultation and speak to a person.'),
                __('Conversations are stored so we can improve the service and answer questions about what was said. Do not type anything into it you would not want kept.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function accountTerms(): array
    {
        return [
            'heading' => __('Your account'),
            'body' => [
                __('One account per person. Keep your password to yourself — anything done from your account is treated as done by you.'),
                __('Give us true information. A seller trading under a name that is not theirs, or a worker claiming experience they do not have, will have the account closed.'),
                __('We may suspend or close an account that breaks these terms, defrauds somebody, or puts other people at risk. Where money is owed to you, closing an account does not cancel that.'),
                __('You can close your own account at any time from your account settings. Records we are required to keep — orders, payments, disputes — survive it.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function changesAndDisputes(): array
    {
        return [
            'heading' => __('Changes, and when we disagree'),
            'body' => [
                __('We may change these terms. If a change matters — commission, refunds, who is responsible for what — we will tell you before it takes effect, not after.'),
                __('If you have a problem with something bought here, use the dispute process first. It is faster than anything else available to you and it freezes the money while it is looked at.'),
                __('These terms are governed by the laws of the Federal Republic of Nigeria.'),
                /*
                 * Deliberately no {company_email} here. That placeholder is
                 * optional branding and expands to an EMPTY STRING when an
                 * administrator has not set an address — which left this
                 * sentence reading "Questions about any of this go to ." on the
                 * terms of service. The contact page always exists and always
                 * shows whichever ways of reaching us are configured.
                 */
                __('Questions about any of this go through the contact page on this site.'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function privacy(): array
    {
        return [
            'title' => __('Privacy policy'),
            'intro' => __('What {company} collects, why, and what we will not do with it.'),
            'updated' => __('Last updated :date', ['date' => now()->format('F Y')]),
            'sections' => [
                [
                    'heading' => __('What we collect'),
                    'body' => [
                        __('Your name, email address, phone number and location, because orders have to reach you and sellers have to be paid.'),
                        __('What you buy, sell, book and apply for, because that is the service.'),
                        __('Bank details for payouts, if you sell or mentor here. We store the account number and the name the bank confirms — never a card number, which our payment providers hold rather than us.'),
                        __('Documents you upload: seller registration papers, worker identification, photographs of a sick animal or a building site.'),
                        __('What you ask the farming assistant, and what it answered.'),
                        __('Basic technical information — your address on the internet, the browser you used, when you signed in — which is what lets us show you where your account is signed in and notice somebody else in it.'),
                    ],
                ],
                [
                    'heading' => __('What we do with it'),
                    'body' => [
                        __('Run the service: take orders, pay sellers, deliver courses, book consultations, match mentors, rank job listings.'),
                        __('Tell you what has happened. You choose which of those reach your inbox, except messages about your money, a dispute or your account security — those always arrive, because not knowing costs you something you cannot undo.'),
                        __('Settle disputes. When two people disagree, we look at the record of what happened, including messages sent through the platform.'),
                        __('Improve the platform, in aggregate. How many people ask the assistant about feed is useful; who asked is not.'),
                    ],
                ],
                [
                    'heading' => __('What we will not do'),
                    'body' => [
                        __('WE DO NOT SELL YOUR DATA. Not to advertisers, not to data brokers, not to anybody.'),
                        __('We do not publish workers\' phone numbers. They go to registered employers only, rate-limited, and every disclosure is logged and reviewed for harvesting.'),
                        __('We do not put contact details in search results, in our sitemap, or anywhere a search engine can reach them.'),
                        __('We do not share a mentor\'s or a buyer\'s contact details before a booking is paid for.'),
                    ],
                ],
                [
                    'heading' => __('Who else sees it'),
                    'body' => [
                        __('Payment providers, to take payments and send payouts. They see what they need to move the money.'),
                        __('The other side of a transaction. A seller sees the delivery address of somebody who orders from them, because otherwise they cannot deliver.'),
                        __('The company that provides the farming assistant\'s language model sees the question you asked and the reference figures we supply with it. It does not receive your name, your email or your account.'),
                        __('Anybody we are legally required to give it to, which we will tell you about unless we are forbidden from doing so.'),
                    ],
                ],
                [
                    'heading' => __('How long we keep it'),
                    'body' => [
                        __('Account details for as long as you have an account, and then as long as we are required to keep records of the transactions on it.'),
                        __('Orders, payments and disputes for seven years, because that is what financial record-keeping requires.'),
                        __('Assistant conversations for two years.'),
                        __('Documents you uploaded for verification are deleted once the verification is settled, except where they form part of a dispute.'),
                    ],
                ],
                [
                    'heading' => __('What you can ask for'),
                    'body' => [
                        __('A copy of what we hold about you.'),
                        __('A correction, if something is wrong. Most of it you can correct yourself in your account.'),
                        __('Deletion of your account and the data that is not tied to a financial record we must keep.'),
                        __('An end to marketing messages, which you can do yourself in your notification settings.'),
                        __('Ask through the contact page on this site and we will answer within thirty days.'),
                    ],
                ],
                [
                    'heading' => __('Keeping it safe'),
                    'body' => [
                        __('Passwords are stored hashed and cannot be read by us or anybody else.'),
                        __('Lesson files and uploaded documents are served through checks rather than sitting on a public address anybody can guess.'),
                        __('You can see where your account is signed in, and sign out everywhere else, from your account settings.'),
                        __('If your data is ever exposed in a way that puts you at risk, we will tell you.'),
                    ],
                ],
            ],
        ];
    }

    /**
     * A percentage as somebody would write it: "5", not "5.0".
     */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }
}
