<?php

namespace App\Content;

use App\Support\Money;

/**
 * The pages that explain what this place is.
 *
 * Written for a farmer, not for an investor. That is a real constraint and it
 * shows up in the sentences: no "leveraging", no "ecosystem", no "empowering" —
 * concrete nouns, ordinary verbs, and a number wherever a number is what
 * somebody actually wants.
 *
 * Every mention of the company is `{company}`, expanded at render time. Nothing
 * in this file names anybody.
 */
class PlatformCopy
{
    /**
     * The eight things this platform does.
     *
     * Ordered by how many people will want them, not by how impressive they
     * are. The marketplace and the free assistant come first because those are
     * what somebody landing here for the first time is most likely to need.
     *
     * @return array<int, array<string, mixed>>
     */
    public function services(): array
    {
        return [
            [
                'key' => 'marketplace',
                'name' => __('The marketplace'),
                'blurb' => __('Feed, cages, drinkers, day-old chicks, fingerlings, produce. Buy from sellers across Nigeria, with your money held until it arrives.'),
                'route' => 'catalogue.home',
                'cta' => __('Browse the market'),
            ],
            [
                'key' => 'assistant',
                'name' => __('The farming assistant'),
                'blurb' => __('Free, instant answers on feeding, housing, water and what things cost. English or Pidgin. No account needed.'),
                'route' => 'assistant.show',
                'cta' => __('Ask a question'),
            ],
            [
                'key' => 'academy',
                'name' => __('Training'),
                'blurb' => __('Courses written by people who have actually raised the birds. Watch on your phone, at your own pace, with a certificate at the end.'),
                'route' => 'academy.home',
                'cta' => __('See the courses'),
            ],
            [
                'key' => 'mentorship',
                'name' => __('Mentorship'),
                'blurb' => __('One-to-one time with an experienced farmer who knows your kind of farming. Invited and checked by us.'),
                'route' => 'mentors.find',
                'cta' => __('Find a mentor'),
            ],
            [
                'key' => 'consultations',
                'name' => __('Consultations'),
                'blurb' => __('Describe a problem, get a price, pay only if you want to go ahead. Urgent option when birds are dying today.'),
                'route' => 'consultations.create',
                'cta' => __('Ask us for help'),
            ],
            [
                'key' => 'quotations',
                'name' => __('Farm setup'),
                'blurb' => __('A written proposal for building or expanding a farm, prepared by hand for your site — something you can take to a bank.'),
                'route' => 'quotations.create',
                'cta' => __('Request a proposal'),
            ],
            [
                'key' => 'jobs',
                'name' => __('Farm jobs'),
                'blurb' => __('Farms looking for workers, workers looking for farms. Free on both sides, and no money passes through us.'),
                'route' => 'jobs.index',
                'cta' => __('See the board'),
            ],
            [
                'key' => 'requests',
                'name' => __('Wanted'),
                'blurb' => __('Cannot find what you need? Post what you are looking for and let sellers come to you with offers.'),
                'route' => 'requests.index',
                'cta' => __('See what people want'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function about(): array
    {
        return [
            'title' => __('About {company}'),
            'lead' => __('{company} is a Nigerian agricultural platform. We sell farm inputs, teach farming, and help people build farms — and we try to do all of it in a way that works on a phone, on patchy data, in a poultry house.'),
            'sections' => [
                [
                    'heading' => __('Why we built it'),
                    'body' => [
                        __('Farming in Nigeria is full of people who know exactly what they are doing and cannot easily reach each other. A farmer in Oyo who has raised broilers for fifteen years, and a man in Kaduna about to lose his first flock, are two phone calls apart and no way to make the call.'),
                        __('At the same time, buying feed means trusting somebody you have never met with money before you see the goods, and there is no way to get it back if the bags never come.'),
                        __('So: one place where the money is held until the goods arrive, where the people who know things can be found and paid properly, and where the basic questions get answered for free at six in the morning.'),
                    ],
                ],
                [
                    'heading' => __('How we make money'),
                    'body' => [
                        __('A commission on marketplace sales, paid by the seller. Course fees. A share of mentorship bookings. Consultation and farm setup fees.'),
                        __('The jobs board and the farming assistant are free and always will be. They cost us money to run and they are worth it — a platform that only helps people who can pay today is not much use to farming in this country.'),
                        __('We do not sell anybody\'s data, and we do not take advertising. What you pay is what we earn.'),
                    ],
                ],
                [
                    'heading' => __('What we are careful about'),
                    'body' => [
                        __('Money. Every naira that moves through {company} is double-entered in a ledger, and disputes freeze funds rather than trusting anybody to be fair afterwards.'),
                        __('Phone numbers. A worker\'s number is shown only to registered employers, and every time it happens is logged. People looking for work are not a mailing list.'),
                        __('Numbers in general. The farming assistant is built so it cannot invent figures — every weight, price and feed amount comes from a published table or from real listings on this site, and it says which.'),
                    ],
                ],
            ],
        ];
    }

    /**
     * One section per service, written the way you would explain it to
     * somebody standing in front of you.
     *
     * @return array<string, mixed>
     */
    public function howItWorks(): array
    {
        $commission = $this->percent((float) settings('marketplace_commission_percent', 5));
        $escrowDays = (int) settings('escrow_auto_release_days', 7);
        $studyFee = Money::fromKobo((int) settings('quotation_study_fee', 5_000_000));
        $validity = (int) settings('quote_validity_days', 30);

        return [
            'title' => __('How {company} works'),
            'lead' => __('Eight services, explained one at a time. Nothing here needs you to know anything about computers.'),
            'services' => [
                [
                    'key' => 'marketplace',
                    'heading' => __('Buying from the marketplace'),
                    'steps' => [
                        __('Find what you need and add it to your basket. You can buy from several sellers at once — it is split up for you afterwards.'),
                        __('Pay with your card or bank transfer. Your money goes to {company}, not straight to the seller.'),
                        __('The seller sends your goods and marks them as sent. You get told.'),
                        __('When it arrives, that is it. The seller is paid :days days later automatically, or straight away if you confirm.', ['days' => $escrowDays]),
                        __('If it does not arrive, or it is not what was listed, open a dispute. The money stays frozen until it is settled.'),
                    ],
                    'note' => __('Buyers pay the listed price. Nothing is added on top.'),
                ],
                [
                    'key' => 'selling',
                    'heading' => __('Selling on the marketplace'),
                    'steps' => [
                        __('Apply to sell. Tell us who you are, what you sell and where you are. We check it by hand.'),
                        __('Once approved, list your products with photographs, a price and how much you have.'),
                        __('When somebody orders, you get told. Pack it, send it, and mark it sent.'),
                        __('The money reaches your {company} balance when the buyer receives it, minus :commission commission.', ['commission' => $commission]),
                        __('Withdraw to your bank whenever you like, as long as no dispute is open on that order.'),
                    ],
                    'note' => __('You keep your own prices, your own stock and your own customers. We take a share of the sale and nothing else.'),
                ],
                [
                    'key' => 'assistant',
                    'heading' => __('Asking the farming assistant'),
                    'steps' => [
                        __('Open it and type your question. No account, no payment, no limit worth worrying about.'),
                        __('Write in English or Pidgin — it answers in whichever you used.'),
                        __('It tells you where its figures come from. If it does not have a number, it says so rather than guessing.'),
                        __('It will not give you drug names or dosages. For that it sends you to a consultation, because a wrong dose kills a flock.'),
                    ],
                    'note' => __('General guidance, not advice about your particular farm. For anything expensive or dangerous, talk to a person.'),
                ],
                [
                    'key' => 'academy',
                    'heading' => __('Taking a course'),
                    'steps' => [
                        __('Look through the courses. Each one says what it covers and how long it takes.'),
                        __('Pay once. Access does not expire and updates are free.'),
                        __('Watch on your phone. It remembers where you stopped.'),
                        __('Pass the quiz at the end and your certificate is issued with your name on it.'),
                    ],
                    'note' => __('Course sales are final — once you can open the lessons you have everything you paid for. Read the course page before you buy, and ask us if you are unsure.'),
                ],
                [
                    'key' => 'mentorship',
                    'heading' => __('Getting a mentor'),
                    'steps' => [
                        __('Describe what you need help with, in your own words.'),
                        __('We shortlist mentors who know that kind of farming.'),
                        __('Pick one and pick a package. Pay for it.'),
                        __('Contact details are exchanged once it is paid, and you arrange the sessions between you.'),
                    ],
                    'note' => __('Mentors are invited and checked by us. They are not our staff, and their advice is their own.'),
                ],
                [
                    'key' => 'consultations',
                    'heading' => __('Booking a consultation'),
                    'steps' => [
                        __('Tell us the problem. Four fields and some photographs — it takes about thirty seconds, and you do not need an account.'),
                        __('We look at it and send you a price. You owe nothing at this point.'),
                        __('Pay if you want to go ahead. We then work on it and send you a written answer.'),
                        __('You can ask follow-up questions on the same consultation afterwards.'),
                    ],
                    'note' => __('Choose urgent if birds are dying today. It costs more and carries a response time in working hours.'),
                ],
                [
                    'key' => 'quotations',
                    'heading' => __('Getting a farm setup proposal'),
                    'steps' => [
                        __('Tell us what you want to build, where, and roughly what you have to spend.'),
                        __('Pay the study fee of :fee. This pays for the work of studying your site and your budget.', ['fee' => $studyFee]),
                        __('We prepare a written proposal by hand — no calculator, no template. It costs, room by room, with quantities.'),
                        __('The proposal is yours to download and take to a bank or a partner.'),
                        __('If you go ahead with us, the study fee comes off the project cost in full.'),
                    ],
                    'note' => __('A proposal is valid for :days days. Accepting one happens offline, between you and us.', ['days' => $validity]),
                ],
                [
                    'key' => 'jobs',
                    'heading' => __('Finding work, or finding workers'),
                    'steps' => [
                        __('Workers: make a profile saying what you can do and where you can work. Apply to anything you like.'),
                        __('Farms: register as an employer and post what you need. It is free.'),
                        __('We rank listings by how well they fit, in both directions. You always see everything — the ranking only changes the order.'),
                        __('Farms contact workers directly. Everything after that is between the two of you.'),
                    ],
                    'note' => __('{company} is not the employer, does not place anybody, and handles no wages. Meet somewhere public, and never pay a fee to be considered for work.'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function faq(): array
    {
        $commission = $this->percent((float) settings('marketplace_commission_percent', 5));
        $escrowDays = (int) settings('escrow_auto_release_days', 7);
        $disputeDays = (int) settings('dispute_window_days', 7);

        return [
            'title' => __('Common questions'),
            'lead' => __('The things people ask most. If yours is not here, write to us.'),
            'groups' => [
                [
                    'heading' => __('Money and safety'),
                    'items' => [
                        [
                            'q' => __('If I pay and the goods never come, do I lose my money?'),
                            'a' => __('No. Your money is held by {company} until the order is delivered. If it does not arrive, open a dispute and the money stays frozen while we look at it.'),
                        ],
                        [
                            'q' => __('How long do I have to complain about an order?'),
                            'a' => __(':days days from delivery. After that the money has usually been released to the seller.', ['days' => $disputeDays]),
                        ],
                        [
                            'q' => __('When do sellers actually get paid?'),
                            'a' => __(':days days after they mark an order delivered, or immediately if the buyer confirms. Nothing is released while a dispute is open.', ['days' => $escrowDays]),
                        ],
                        [
                            'q' => __('What does {company} charge?'),
                            'a' => __('Sellers pay :commission commission on a sale. Buyers pay the listed price with nothing added. Courses, consultations and farm setup proposals have their own prices, shown before you pay.', ['commission' => $commission]),
                        ],
                        [
                            'q' => __('Do you keep my card details?'),
                            'a' => __('No. Card details go to our payment provider and never reach us. We store your bank account for payouts if you sell here, and only the account number and the name your bank confirms.'),
                        ],
                    ],
                ],
                [
                    'heading' => __('Selling'),
                    'items' => [
                        [
                            'q' => __('Can anybody sell here?'),
                            'a' => __('You apply and we check it by hand. We ask who you are, what you sell and where you are based. It is not automatic.'),
                        ],
                        [
                            'q' => __('Can I sell live birds and fish?'),
                            'a' => __('Yes. Live animals and perishables carry extra rules about how they are listed and delivered, because a dispute about a dead bird a week later is hard for anybody to settle fairly.'),
                        ],
                        [
                            'q' => __('Somebody wants to pay less than my listed price.'),
                            'a' => __('That is what offers are for. A buyer can make you an offer, you can counter it, and if you agree you get a private checkout link at the agreed price.'),
                        ],
                    ],
                ],
                [
                    'heading' => __('Training and advice'),
                    'items' => [
                        [
                            'q' => __('Can I get a refund on a course?'),
                            'a' => __('No — course sales are final. The moment you can open the lessons you have received the whole product, and we cannot take it back. Read the course page first, and ask us if you are unsure.'),
                        ],
                        [
                            'q' => __('Is the certificate worth anything?'),
                            'a' => __('It says you completed our course, and it carries your name and a reference anybody can check with us. It is not a government qualification and we do not pretend it is.'),
                        ],
                        [
                            'q' => __('Is the farming assistant really free?'),
                            'a' => __('Yes, and you do not need an account. There is a daily limit to stop it being used as somebody else\'s free service, generous enough that ordinary use will not reach it.'),
                        ],
                        [
                            'q' => __('Why will the assistant not tell me what drug to give?'),
                            'a' => __('Because a wrong dose kills birds. Anything that amounts to a prescription needs a vet who has seen the animals — the assistant will point you at a consultation instead.'),
                        ],
                        [
                            'q' => __('What is the difference between a mentor and a consultation?'),
                            'a' => __('A mentor is an independent farmer you build a relationship with over several sessions. A consultation is a specific problem answered by {company} in writing, once. Sick birds today: consultation. Learning to run a farm: mentor.'),
                        ],
                    ],
                ],
                [
                    'heading' => __('Jobs'),
                    'items' => [
                        [
                            'q' => __('Does {company} employ the workers on the board?'),
                            'a' => __('No. We are not the employer, we place nobody, we pay no wages and we are not part of any employment agreement made through this board. Farms and workers deal with each other directly.'),
                        ],
                        [
                            'q' => __('Do you check the farms advertising?'),
                            'a' => __('No. There is no verification step. Meet somewhere public first, and never pay anybody a fee to be considered for work.'),
                        ],
                        [
                            'q' => __('Who can see my phone number?'),
                            'a' => __('Only registered employers, a limited number of times a day, and every time it happens is recorded. Anonymous visitors never see it. We watch for accounts collecting numbers and close them.'),
                        ],
                    ],
                ],
                [
                    'heading' => __('Using the site'),
                    'items' => [
                        [
                            'q' => __('It is slow on my phone.'),
                            'a' => __('The site is built for patchy mobile data — small images, little to download, and pages that work before everything has loaded. If it is still slow, tell us which page and we will look.'),
                        ],
                        [
                            'q' => __('Can I stop the emails?'),
                            'a' => __('Most of them, in your account settings, by category. Messages about your money, a dispute or your account security always arrive — not knowing those costs you something you cannot undo.'),
                        ],
                        [
                            'q' => __('Do I need an account?'),
                            'a' => __('Not to browse, to ask the assistant, or to book a consultation. You need one to buy, sell, enrol on a course or apply for a job.'),
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * The three role guides.
     *
     * @return array<string, array<string, mixed>>
     */
    public function guides(): array
    {
        return [
            'seller' => [
                'title' => __('Selling on {company}'),
                'lead' => __('What it takes to sell here, and how to do well at it.'),
                'sections' => [
                    [
                        'heading' => __('Getting approved'),
                        'body' => [
                            __('Apply with your real business details. We check them by hand, usually within a couple of working days.'),
                            __('Have ready: what you sell, where you trade from, a phone number that works, and your bank account for payouts.'),
                            __('If we ask for more information, it is because something did not add up — answer it and the application carries on from where it was.'),
                        ],
                    ],
                    [
                        'heading' => __('Listings that sell'),
                        'body' => [
                            __('Photograph the actual goods, in daylight, on a plain background. A real photograph of a real bag outsells a manufacturer\'s picture every time.'),
                            __('Put the size in the title. "Broiler starter, 25 kg bag" tells somebody everything; "Quality feed" tells them nothing.'),
                            __('Say what is really in stock. A buyer who orders something you do not have opens a dispute, and disputes cost you more than the sale was worth.'),
                            __('Price including what it costs you to pack it. Delivery is set separately, per state.'),
                        ],
                    ],
                    [
                        'heading' => __('Getting paid'),
                        'body' => [
                            __('Money reaches your balance when the buyer receives the goods, not when they pay.'),
                            __('Mark orders as sent the day you send them. The release clock does not start until you do.'),
                            __('Add your bank account before your first sale, not after. The bank confirms the account name, so it has to match.'),
                            __('An open dispute freezes that order\'s money — the rest of your balance is unaffected.'),
                        ],
                    ],
                    [
                        'heading' => __('Live animals and perishables'),
                        'body' => [
                            __('Be specific about age, breed and condition. "Day-old" means today, not this week.'),
                            __('Say how they travel and how long they can be in transit.'),
                            __('Deliver quickly and tell the buyer when you set off. Most disputes on live goods are really about somebody not knowing when to expect them.'),
                        ],
                    ],
                ],
            ],
            'mentor' => [
                'title' => __('Mentoring on {company}'),
                'lead' => __('Mentors join by invitation only. There is no public signup — if you are reading this, either we have written to you or somebody has recommended you.'),
                'sections' => [
                    [
                        'heading' => __('How you join'),
                        'body' => [
                            __('We invite you by email with a private link. The link is yours alone and expires.'),
                            __('You fill in what you know, how long you have farmed, and what you are willing to help with.'),
                            __('You set your own packages and your own prices. We do not set them for you.'),
                        ],
                    ],
                    [
                        'heading' => __('Getting matched'),
                        'body' => [
                            __('Farmers describe their problem in their own words, and we match it to the specialisations on your profile.'),
                            __('Be specific in those. "Poultry" matches everything and therefore nothing useful; "brooding", "layer nutrition" and "disease control" bring you the people you can actually help.'),
                            __('Your contact details are never shown before a booking is paid for. You will not be cold-called from this site.'),
                        ],
                    ],
                    [
                        'heading' => __('Sessions and payment'),
                        'body' => [
                            __('Once a client pays, you both get each other\'s details and arrange the sessions between you.'),
                            __('Mark a package finished when the work is done. The money moves after that, minus our share.'),
                            __('If a client disputes, the money for that engagement is frozen until it is settled. Keep notes of what you covered.'),
                        ],
                    ],
                ],
            ],
            'worker' => [
                'title' => __('Finding farm work on {company}'),
                'lead' => __('The board is free, and it always will be. Nobody here should ever ask you for money.'),
                'sections' => [
                    [
                        'heading' => __('Your profile'),
                        'body' => [
                            __('Say what you can actually do — feeding, brooding, vaccination, fish ponds, driving, records. Farms search on those.'),
                            __('Say where you can work and whether you can live on site. Most farm work is decided on distance.'),
                            __('Your phone number is not public. Only registered employers see it, a limited number a day, and every time is recorded.'),
                        ],
                    ],
                    [
                        'heading' => __('Applying'),
                        'body' => [
                            __('You can apply once to each listing. There is no advantage to applying twice and no way to.'),
                            __('Say briefly why you fit that farm. A sentence about the work you have done beats a long list of everything you have ever done.'),
                            __('Listings are ranked by how well they fit you, but you always see all of them — nothing is hidden from you.'),
                        ],
                    ],
                    [
                        'heading' => __('Staying safe'),
                        'body' => [
                            __('{company} IS NOT THE EMPLOYER. We do not check farms, place anybody or handle wages. The agreement is between you and the farm.'),
                            __('Never pay a fee to be considered for work. No legitimate farm on this board will ask for one, and anybody who does should be reported to us.'),
                            __('Meet somewhere public the first time, and tell somebody where you are going.'),
                            __('Agree the wage, the hours and where you will sleep before you travel. Get it in writing if you can, even a message.'),
                            __('If a listing is not what it claimed, tell us. We can take it down.'),
                        ],
                    ],
                ],
            ],
        ];
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.').'%';
    }
}
