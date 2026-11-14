import { Form, Head, usePage } from '@inertiajs/react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { login } from '@/routes';
import { store } from '@/routes/sso';

/**
 * Signing in through an organisation's identity provider: the email
 * address's domain says which.
 */
export default function SsoSignIn() {
    // An identity provider's refusal comes back on a redirect, not from the form.
    const refused = usePage().props.errors.email;

    return (
        <>
            <Head title="Single sign-on" />

            <Form {...store.form()} className="flex flex-col gap-6">
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-2">
                            <Label htmlFor="email">Work email address</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                autoComplete="email"
                                placeholder="you@company.com"
                            />
                            <InputError message={errors.email ?? refused} />
                        </div>

                        <Button
                            type="submit"
                            className="w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            Continue
                        </Button>
                    </>
                )}
            </Form>

            <div className="mt-6 space-x-1 text-center text-sm text-muted-foreground">
                <span>Or</span>
                <TextLink href={login()}>log in with a password</TextLink>
            </div>
        </>
    );
}

SsoSignIn.layout = [
    AuthLayout,
    {
        title: 'Single sign-on',
        description:
            "Sign in through your organisation's identity provider, such as Microsoft Entra, Okta or Google",
    },
];
