<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Button from '@/Components/Ui/Button.vue';
import Input from '@/Components/Ui/Input.vue';
import Badge from '@/Components/Ui/Badge.vue';

/**
 * Everything about your own account, in one place.
 *
 * Sections are anchored rather than tabbed. A tab hides its contents from
 * Ctrl-F and from anybody arriving on a link, and this page is exactly the sort
 * of thing people arrive at from a support message saying "turn that off under
 * notifications".
 */
const props = defineProps({
    profile: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    notificationPreferences: { type: Array, default: () => [] },
    canBePaid: { type: Boolean, default: false },
    payoutAccounts: { type: Array, default: () => [] },
    bankOptions: { type: Object, default: () => ({}) },
    sessions: { type: Array, default: () => [] },
});

const profileForm = useForm({
    name: props.profile.name ?? '',
    email: props.profile.email ?? '',
    phone: props.profile.phone ?? '',
    state: props.profile.state ?? '',
    city: props.profile.city ?? '',
});

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const sessionsForm = useForm({ password: '' });

// Seeded from the server so a category added in code shows up immediately.
const preferences = ref(
    Object.fromEntries(
        props.notificationPreferences.map((row) => [
            row.category,
            { database: row.database, email: row.email },
        ]),
    ),
);

const savingPreferences = ref(false);

const sections = computed(() =>
    [
        { id: 'profile', label: 'Your details' },
        props.canBePaid ? { id: 'payouts', label: 'Getting paid' } : null,
        { id: 'notifications', label: 'What we tell you' },
        { id: 'security', label: 'Password and sign-ins' },
    ].filter(Boolean),
);

function saveProfile() {
    profileForm.patch(route('profile.update'), { preserveScroll: true });
}

function savePassword() {
    passwordForm.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}

function savePreferences() {
    savingPreferences.value = true;

    router.put(
        route('account.notifications.update'),
        { preferences: preferences.value },
        {
            preserveScroll: true,
            onFinish: () => {
                savingPreferences.value = false;
            },
        },
    );
}

function signOutEverywhereElse() {
    sessionsForm.delete(route('account.sessions.destroy'), {
        preserveScroll: true,
        onSuccess: () => sessionsForm.reset(),
    });
}
</script>

<template>
    <AppLayout title="Your account">
        <div class="mx-auto w-full max-w-3xl">
            <h1 class="text-2xl sm:text-3xl">Your account</h1>
            <p class="mt-2 text-sm text-muted">
                Your details, how you get paid, what we tell you about, and where you are signed in.
            </p>

            <div v-if="roles.length" class="mt-3 flex flex-wrap gap-2">
                <Badge v-for="role in roles" :key="role" variant="chrome">{{ role }}</Badge>
            </div>

            <!-- Anchors rather than tabs: findable, linkable, and Ctrl-F works. -->
            <nav class="mt-5 flex flex-wrap gap-2" aria-label="Sections of this page">
                <a
                    v-for="section in sections"
                    :key="section.id"
                    :href="`#${section.id}`"
                    class="rounded-sm border-2 border-chrome px-3 py-1.5 text-xs font-bold uppercase tracking-wider text-muted hover:border-ink hover:text-ink dark:border-grain-700 dark:hover:border-wash dark:hover:text-wash"
                >
                    {{ section.label }}
                </a>
            </nav>

            <hr class="seam seam-chrome my-6" />

            <!-- Your details -->
            <section id="profile" class="scroll-mt-24">
                <h2 class="text-xl">Your details</h2>
                <Card class="mt-3">
                    <form class="space-y-4" @submit.prevent="saveProfile">
                        <Input
                            v-model="profileForm.name"
                            label="Name"
                            :error="profileForm.errors.name"
                            required
                        />
                        <Input
                            v-model="profileForm.email"
                            type="email"
                            label="Email"
                            :error="profileForm.errors.email"
                            required
                        />
                        <p v-if="!profile.emailVerified" class="text-sm text-cockscomb">
                            Your email is not confirmed yet. Some messages will not reach you until it is.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <Input v-model="profileForm.phone" label="Phone" :error="profileForm.errors.phone" />
                            <Input v-model="profileForm.state" label="State" :error="profileForm.errors.state" />
                        </div>
                        <Input v-model="profileForm.city" label="Town or city" :error="profileForm.errors.city" />

                        <Button type="submit" :loading="profileForm.processing">Save details</Button>
                    </form>
                </Card>
            </section>

            <!-- Getting paid, only for people who can be -->
            <section v-if="canBePaid" id="payouts" class="mt-8 scroll-mt-24">
                <h2 class="text-xl">Getting paid</h2>
                <p class="mt-1 text-sm text-muted">
                    Where your money goes when you withdraw it.
                </p>

                <Card class="mt-3">
                    <div v-if="!payoutAccounts.length" class="text-sm text-muted">
                        You have not added a bank account yet. You will need one before you can withdraw.
                    </div>

                    <ul v-else class="space-y-3">
                        <li
                            v-for="account in payoutAccounts"
                            :key="account.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-sm border-2 border-chrome px-3 py-2.5 dark:border-grain-700"
                        >
                            <div class="min-w-0">
                                <p class="font-semibold">{{ account.bank }}</p>
                                <p class="figures text-sm text-muted">{{ account.masked }}</p>
                                <p v-if="account.name" class="text-sm text-muted">{{ account.name }}</p>
                            </div>
                            <Badge v-if="account.default" variant="enamel">Default</Badge>
                        </li>
                    </ul>

                    <p class="mt-4 text-xs text-muted">
                        Bank details are added and changed in your seller or mentor panel, where the bank
                        confirms the account name before it is saved.
                    </p>
                </Card>
            </section>

            <!-- Notification preferences -->
            <section id="notifications" class="mt-8 scroll-mt-24">
                <h2 class="text-xl">What we tell you</h2>
                <p class="mt-1 text-sm text-muted">
                    Choose what reaches your inbox. Everything still appears here in the app unless you
                    turn that off too.
                </p>

                <Card class="mt-3">
                    <div class="space-y-4">
                        <div
                            v-for="row in notificationPreferences"
                            :key="row.category"
                            class="border-b-2 border-dotted border-chrome pb-4 last:border-0 last:pb-0 dark:border-grain-700"
                        >
                            <p class="font-semibold">{{ row.label }}</p>
                            <p class="mt-0.5 text-sm text-muted">{{ row.description }}</p>

                            <div class="mt-2.5 flex flex-wrap gap-x-6 gap-y-2">
                                <label class="flex items-center gap-2 text-sm">
                                    <input
                                        v-model="preferences[row.category].database"
                                        type="checkbox"
                                        class="size-4 rounded-sm border-2 border-ink dark:border-wash"
                                    />
                                    Show in the app
                                </label>

                                <label class="flex items-center gap-2 text-sm" :class="row.essential ? 'opacity-60' : ''">
                                    <input
                                        v-model="preferences[row.category].email"
                                        type="checkbox"
                                        :disabled="row.essential"
                                        class="size-4 rounded-sm border-2 border-ink dark:border-wash"
                                    />
                                    Email me
                                </label>
                            </div>

                            <!--
                                Said out loud rather than shown as a switch that
                                quietly does nothing. "Why am I still getting
                                these" is a question somebody will ask, and the
                                answer belongs on this screen.
                            -->
                            <p v-if="row.essential" class="mt-1.5 text-xs text-muted">
                                Always emailed. This is about your money, a dispute, or your account
                                security — not knowing costs you something you cannot undo.
                            </p>
                        </div>
                    </div>

                    <Button class="mt-5" :loading="savingPreferences" @click="savePreferences">
                        Save preferences
                    </Button>
                </Card>
            </section>

            <!-- Password and sessions -->
            <section id="security" class="mt-8 scroll-mt-24">
                <h2 class="text-xl">Password and sign-ins</h2>

                <Card class="mt-3">
                    <form class="space-y-4" @submit.prevent="savePassword">
                        <h3 class="stencil text-enamel dark:text-chrome">Change password</h3>
                        <Input
                            v-model="passwordForm.current_password"
                            type="password"
                            label="Current password"
                            autocomplete="current-password"
                            :error="passwordForm.errors.current_password"
                        />
                        <Input
                            v-model="passwordForm.password"
                            type="password"
                            label="New password"
                            autocomplete="new-password"
                            :error="passwordForm.errors.password"
                        />
                        <Input
                            v-model="passwordForm.password_confirmation"
                            type="password"
                            label="Repeat new password"
                            autocomplete="new-password"
                            :error="passwordForm.errors.password_confirmation"
                        />
                        <Button type="submit" :loading="passwordForm.processing">Change password</Button>
                    </form>
                </Card>

                <Card v-if="sessions.length" class="mt-4">
                    <h3 class="stencil text-enamel dark:text-chrome">Where you are signed in</h3>

                    <ul class="mt-3 space-y-2">
                        <li
                            v-for="session in sessions"
                            :key="session.id"
                            class="flex flex-wrap items-center justify-between gap-2 rounded-sm border-2 border-chrome px-3 py-2 text-sm dark:border-grain-700"
                        >
                            <div class="min-w-0">
                                <p class="font-semibold">
                                    {{ session.agent }}
                                    <Badge v-if="session.current" variant="enamel" class="ml-1">This device</Badge>
                                </p>
                                <p class="figures text-xs text-muted">{{ session.ip }} · {{ session.last }}</p>
                            </div>
                        </li>
                    </ul>

                    <!--
                        One button, not a revoke per row. Somebody on this screen
                        thinks a stranger has their password; asking them to pick
                        the stranger out of a list of user agents is the wrong
                        thing to ask at that moment.
                    -->
                    <form class="mt-4 space-y-3" @submit.prevent="signOutEverywhereElse">
                        <Input
                            v-model="sessionsForm.password"
                            type="password"
                            label="Confirm your password to sign out everywhere else"
                            autocomplete="current-password"
                            :error="sessionsForm.errors.password"
                        />
                        <Button type="submit" variant="danger" :loading="sessionsForm.processing">
                            Sign out everywhere else
                        </Button>
                    </form>
                </Card>
            </section>

            <p class="mt-8 text-xs text-muted">
                Need to close your account?
                <Link :href="route('profile.edit')" class="underline underline-offset-4">Manage that here</Link>.
            </p>
        </div>
    </AppLayout>
</template>
