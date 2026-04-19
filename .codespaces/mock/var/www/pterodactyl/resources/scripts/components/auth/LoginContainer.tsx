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

const LoginContainer = ({ history }: RouteComponentProps) => {
    const ref = useRef<any>(null);
    const [token, setToken] = useState('');
    const [captchaReady, setCaptchaReady] = useState(false);
    const [captchaLoadError, setCaptchaLoadError] = useState(false);

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
                    // @ts-expect-error this is valid
                    window.location = response.intended || '/';
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
                <LoginFormContainer title={'Login to Continue'}>
                    <Field light type={'text'} label={'Username or Email'} name={'username'} disabled={isSubmitting} />
                    <div css={tw`mt-6`}>
                        <Field light type={'password'} label={'Password'} name={'password'} disabled={isSubmitting} />
                    </div>
                    <div css={tw`mt-6`}>
                        <Button type={'submit'} size={'xlarge'} isLoading={isSubmitting} disabled={isSubmitting}>
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
                            css={tw`inline-flex items-center justify-center px-5 py-2 rounded-md bg-cyan-600 text-white text-xs font-semibold tracking-wide uppercase no-underline shadow-md hover:bg-cyan-500 transition-colors`}
                        >
                            Daftar
                        </Link>
                    </div>
                </LoginFormContainer>
            )}
        </Formik>
    );
};

export default LoginContainer;
