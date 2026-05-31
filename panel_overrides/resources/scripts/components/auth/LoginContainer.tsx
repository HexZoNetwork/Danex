import React, { useEffect, useRef, useState } from 'react';
import { Link, RouteComponentProps } from 'react-router-dom';
import login from '@/api/auth/login';
import LoginFormContainer from '@/components/auth/LoginFormContainer';
import { useStoreState } from 'easy-peasy';
import { Formik, FormikHelpers } from 'formik';
import { object, string } from 'yup';
import Field from '@/components/elements/Field';
import tw from 'twin.macro';
import Button from '@/components/elements/Button';
import useFlash from '@/plugins/useFlash';
import Reaptcha from 'reaptcha';

interface Values {
    username: string;
    password: string;
}

type AssetManifest = Record<string, { src?: string }>;

const preloadCoreAssets = async (onProgress: (progress: number) => void): Promise<void> => {
    const connection = (navigator as any).connection;
    if (connection?.saveData || ['slow-2g', '2g'].includes(String(connection?.effectiveType || ''))) {
        onProgress(100);
        return;
    }

    const controller = new AbortController();
    const timeout = new Promise<void>((resolve) =>
        window.setTimeout(() => {
            controller.abort();
            resolve();
        }, 1800)
    );
    const preload = async () => {
        const manifest = (await fetch('/assets/manifest.json', { cache: 'no-cache', credentials: 'same-origin', signal: controller.signal }).then((r) =>
            r.ok ? r : Promise.reject(new Error(`Manifest preload failed with status ${r.status}`))
        ).then((r) =>
            r.json()
        )) as AssetManifest;
        const assets = ['runtime.js', 'framework.js', 'vendors.js', 'main.js', 'dashboard.js']
            .map((name) => manifest[name]?.src)
            .filter((src): src is string => typeof src === 'string' && src.endsWith('.js'));
        const uniqueAssets = Array.from(new Set(assets)).slice(0, 5);
        let completed = 0;

        if (uniqueAssets.length === 0) {
            onProgress(100);
            return;
        }

        await Promise.allSettled(
            uniqueAssets.map(async (src) => {
                const link = document.createElement('link');
                link.rel = 'prefetch';
                link.setAttribute('as', 'script');
                link.href = src;
                document.head.appendChild(link);
                completed += 1;
                onProgress(Math.round((completed / uniqueAssets.length) * 100));
            })
        );
    };

    onProgress(16);
    await Promise.race([preload(), timeout]);
    onProgress(100);
};

const BootOverlay = ({ progress }: { progress: number }) => (
    <div
        css={tw`fixed inset-0 z-50 flex items-center justify-center px-6`}
        style={{ background: 'rgba(7, 7, 11, 0.94)', backdropFilter: 'blur(16px)' }}
    >
        <div
            css={tw`w-full max-w-md rounded-lg border p-5 text-center`}
            style={{
                background: '#0b0b10',
                borderColor: 'rgba(139, 92, 246, 0.36)',
                boxShadow: '0 26px 80px rgba(0, 0, 0, 0.65), 0 0 34px rgba(139, 92, 246, 0.16)',
            }}
        >
            <p css={tw`text-[11px] uppercase tracking-widest text-neutral-500`}>DANEX X EL7</p>
            <h1 css={tw`mt-2 text-2xl font-semibold text-neutral-100`}>Registering The Core</h1>
            <p css={tw`mt-2 text-sm text-neutral-400`}>Preparing dashboard assets...</p>
            <div css={tw`mt-5 h-2 rounded-full overflow-hidden border`} style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.2)' }}>
                <div
                    css={tw`h-full transition-all duration-200`}
                    style={{
                        width: `${Math.max(8, progress)}%`,
                        background: '#8b5cf6',
                        boxShadow: '0 0 18px rgba(139, 92, 246, 0.56)',
                    }}
                />
            </div>
            <p css={tw`mt-3 text-xs text-purple-200 tabular-nums`}>{progress}%</p>
        </div>
    </div>
);

const LoginContainer = ({ history }: RouteComponentProps) => {
    const ref = useRef<any>(null);
    const [token, setToken] = useState('');
    const [captchaReady, setCaptchaReady] = useState(false);
    const [captchaLoadError, setCaptchaLoadError] = useState(false);
    const [booting, setBooting] = useState(false);
    const [bootProgress, setBootProgress] = useState(0);

    const { clearFlashes, clearAndAddHttpError, addFlash } = useFlash();
    const recaptcha = useStoreState((state) => state.settings.data?.recaptcha);
    const siteKey = recaptcha?.siteKey ?? '';
    // Only enable recaptcha if it's enabled in settings and a site key is provided.
    const recaptchaEnabled = !!recaptcha?.enabled && !!siteKey;

    useEffect(() => {
        clearFlashes();
    }, [clearFlashes]);

    const executeCaptcha = (setSubmitting: (isSubmitting: boolean) => void, attempt = 0) => {
        if (!ref.current || typeof ref.current.execute !== 'function') {
            setCaptchaLoadError(true);
            setSubmitting(false);
            addFlash({
                type: 'error',
                title: 'Captcha Error',
                message: 'Captcha belum siap. Tunggu sebentar lalu coba login lagi.',
            });
            return;
        }

        ref.current.execute().catch((error: any) => {
            const msg = String(error?.message || '');
            const notRenderedYet = msg.toLowerCase().includes('did not render yet');
            if (notRenderedYet && attempt < 3) {
                window.setTimeout(() => executeCaptcha(setSubmitting, attempt + 1), 250);
                return;
            }

            console.error(error);
            setCaptchaLoadError(true);
            setSubmitting(false);
            clearAndAddHttpError({ error });
        });
    };

    const onSubmit = (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes();

        // If there is no token in the state yet, request the token and then abort this submit request
        // since it will be re-submitted when the recaptcha data is returned by the component.
        if (recaptchaEnabled && !token) {
            if (!captchaReady) {
                setCaptchaLoadError(true);
                setSubmitting(false);
                addFlash({
                    type: 'error',
                    title: 'Captcha Error',
                    message: 'Captcha belum siap. Tunggu 1-2 detik lalu klik Login lagi.',
                });
                return;
            }
            executeCaptcha(setSubmitting);

            return;
        }

        login({ ...values, recaptchaData: token })
            .then((response) => {
                if (response.complete) {
                    setBooting(true);
                    void preloadCoreAssets(setBootProgress).finally(() => {
                        // @ts-expect-error this is valid
                        window.location = response.intended || '/';
                    });
                    return;
                }

                history.replace('/auth/login/checkpoint', { token: response.confirmationToken });
            })
            .catch((error) => {
                console.error(error);

                setToken('');
                if (ref.current) ref.current.reset();

                setSubmitting(false);
                clearAndAddHttpError({ error });
            });
    };

    return (
        <Formik
            onSubmit={onSubmit}
            initialValues={{ username: '', password: '' }}
            validationSchema={object().shape({
                username: string().required('A username or email must be provided.'),
                password: string().required('Please enter your account password.'),
            })}
        >
            {({ isSubmitting, setSubmitting, submitForm }) => (
                <>
                    {booting && <BootOverlay progress={bootProgress} />}
                    <LoginFormContainer title={'Sign In'}>
                    <Field light type={'text'} label={'Username or Email'} name={'username'} disabled={isSubmitting || booting} />
                    <div css={tw`mt-6`}>
                        <Field light type={'password'} label={'Password'} name={'password'} disabled={isSubmitting || booting} />
                    </div>
                    <div css={tw`mt-6`}>
                        <Button type={'submit'} size={'xlarge'} isLoading={isSubmitting || booting} disabled={isSubmitting || booting}>
                            Login
                        </Button>
                    </div>
                    {recaptchaEnabled && (
                        <Reaptcha
                            ref={ref}
                            size={'invisible'}
                            sitekey={siteKey}
                            onVerify={(response: string) => {
                                setCaptchaLoadError(false);
                                setToken(response);
                                submitForm();
                            }}
                            onLoad={() => {
                                setCaptchaReady(true);
                                setCaptchaLoadError(false);
                            }}
                            onExpire={() => {
                                setCaptchaReady(false);
                                setSubmitting(false);
                                setToken('');
                            }}
                            onError={() => {
                                setCaptchaReady(false);
                                setCaptchaLoadError(true);
                                setSubmitting(false);
                            }}
                        />
                    )}
                    {captchaLoadError && (
                        <p css={tw`mt-3 text-center text-xs text-red-500`}>
                            Captcha belum siap. Tunggu sebentar lalu klik Login lagi.
                        </p>
                    )}
                    <div css={tw`mt-6 text-center`}>
                        <Link
                            to={'/auth/password'}
                            css={tw`text-xs text-neutral-500 tracking-wide no-underline uppercase hover:text-neutral-600`}
                        >
                            Forgot password?
                        </Link>
                    </div>
                    <div css={tw`mt-3 text-center`}>
                        <Link
                            to={'/auth/register'}
                            css={tw`inline-flex items-center justify-center px-5 py-2 rounded-md text-white text-xs font-semibold tracking-wide uppercase no-underline shadow-md transition-colors border`}
                            style={{
                                background: '#111117',
                                borderColor: 'rgba(139, 92, 246, 0.46)',
                                boxShadow: '0 0 20px rgba(139, 92, 246, 0.14)',
                            }}
                        >
                            Daftar
                        </Link>
                    </div>
                    </LoginFormContainer>
                </>
            )}
        </Formik>
    );
};

export default LoginContainer;
