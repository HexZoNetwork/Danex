import React, { useEffect, useState } from 'react';
import tw from 'twin.macro';
import { Link } from 'react-router-dom';
import LoginFormContainer from '@/components/auth/LoginFormContainer';
import Field from '@/components/elements/Field';
import { Formik, FormikHelpers } from 'formik';
import { object, string } from 'yup';
import Button from '@/components/elements/Button';
import { getRegisterMeta, startRegister, verifyRegisterOtp } from '@/api/auth/register';
import { httpErrorToHuman } from '@/api/http';
import useFlash from '@/plugins/useFlash';

interface RegisterValues {
    email: string;
    username: string;
    name_first: string;
    password: string;
    telegram_id: string;
}

interface OtpValues {
    otp: string;
}

export default () => {
    const { clearFlashes, addFlash } = useFlash();
    const [requestToken, setRequestToken] = useState('');
    const [step, setStep] = useState<'register' | 'otp'>('register');
    const [registerPayload, setRegisterPayload] = useState<RegisterValues | null>(null);
    const [resending, setResending] = useState(false);
    const [botUsername, setBotUsername] = useState('');
    const [botStartUrl, setBotStartUrl] = useState('');
    const [telegramReady, setTelegramReady] = useState(true);

    useEffect(() => {
        clearFlashes();
        getRegisterMeta()
            .then((meta) => {
                setBotUsername(meta.botUsername || '');
                setBotStartUrl(meta.botStartUrl || '');
                setTelegramReady(meta.telegramReady);
            })
            .catch(() => {
                setTelegramReady(false);
            });
    }, []);

    const submitRegister = async (values: RegisterValues, { setSubmitting }: FormikHelpers<RegisterValues>) => {
        clearFlashes();

        try {
            const response = await startRegister(values);
            setRequestToken(response.requestToken);
            if (response.botUsername) setBotUsername(response.botUsername);
            if (response.botStartUrl) setBotStartUrl(response.botStartUrl);
            setRegisterPayload(values);
            setStep('otp');
            addFlash({ type: 'success', title: 'OTP Terkirim', message: 'OTP sudah dikirim ke Telegram kamu.' });
        } catch (error) {
            addFlash({ type: 'error', title: 'Gagal', message: httpErrorToHuman(error) });
        } finally {
            setSubmitting(false);
        }
    };

    const submitOtp = async ({ otp }: OtpValues, { setSubmitting }: FormikHelpers<OtpValues>) => {
        clearFlashes();

        try {
            await verifyRegisterOtp(requestToken, otp);
            window.location.href = '/';
        } catch (error) {
            addFlash({ type: 'error', title: 'OTP Gagal', message: httpErrorToHuman(error) });
            setSubmitting(false);
        }
    };

    if (step === 'otp' && requestToken !== '') {
        return (
            <Formik
                initialValues={{ otp: '' }}
                validationSchema={object().shape({
                    otp: string().required('OTP wajib diisi.').matches(/^[0-9]{6}$/, 'OTP harus 6 digit angka.'),
                })}
                onSubmit={submitOtp}
            >
                {({ isSubmitting }) => (
                    <LoginFormContainer title={'Verifikasi OTP'} css={tw`w-full max-w-md mx-auto`} compact hideLogo>
                        <div css={tw`mb-4 flex rounded-lg border border-neutral-300 overflow-hidden`}>
                            <button
                                type={'button'}
                                css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide bg-neutral-200 text-neutral-600`}
                                onClick={() => setStep('register')}
                            >
                                Data Akun
                            </button>
                            <button
                                type={'button'}
                                css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide bg-cyan-600 text-white`}
                            >
                                Kode OTP
                            </button>
                        </div>
                        <Field
                            light
                            type={'text'}
                            label={'Kode OTP Telegram'}
                            name={'otp'}
                            disabled={isSubmitting}
                            description={'Masukkan OTP dari bot Telegram untuk menyelesaikan pendaftaran.'}
                        />
                        <div css={tw`mt-6`}>
                            <Button type={'submit'} size={'xlarge'} isLoading={isSubmitting} disabled={isSubmitting}>
                                Verifikasi & Masuk
                            </Button>
                        </div>
                        <div css={tw`mt-4 text-center`}>
                            <Button
                                type={'button'}
                                size={'small'}
                                disabled={isSubmitting || resending || !registerPayload}
                                isLoading={resending}
                                onClick={async () => {
                                    if (!registerPayload) return;
                                    clearFlashes();
                                    setResending(true);
                                    try {
                                        const response = await startRegister(registerPayload);
                                        setRequestToken(response.requestToken);
                                        addFlash({ type: 'success', title: 'Berhasil', message: 'Kode OTP baru sudah dikirim.' });
                                    } catch (error) {
                                        addFlash({ type: 'error', title: 'Gagal Kirim Ulang', message: httpErrorToHuman(error) });
                                    } finally {
                                        setResending(false);
                                    }
                                }}
                            >
                                Kirim Ulang Kode
                            </Button>
                        </div>
                        <div css={tw`mt-4 text-center`}>
                            <Link
                                to={'/auth/login'}
                                css={tw`text-xs text-neutral-500 tracking-wide uppercase no-underline hover:text-neutral-700`}
                            >
                                Kembali ke Login
                            </Link>
                        </div>
                    </LoginFormContainer>
                )}
            </Formik>
        );
    }

    return (
        <Formik
            initialValues={{
                email: '',
                username: '',
                name_first: '',
                password: '',
                telegram_id: '',
            }}
            validationSchema={object().shape({
                email: string().email('Email tidak valid.').required('Email wajib diisi.'),
                username: string().required('Username wajib diisi.').max(191),
                name_first: string().required('FirstName wajib diisi.').max(191),
                password: string().required('Password wajib diisi.').min(8),
                telegram_id: string()
                    .required('ID Telegram wajib diisi.')
                    .matches(/^-?[0-9]{5,20}$/, 'ID Telegram harus angka.'),
            })}
            onSubmit={submitRegister}
        >
            {({ isSubmitting }) => (
                <LoginFormContainer title={'Daftar Akun'} css={tw`w-full max-w-md mx-auto`} compact hideLogo>
                    <div css={tw`mb-4 flex rounded-lg border border-neutral-300 overflow-hidden`}>
                        <button
                            type={'button'}
                            css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide bg-cyan-600 text-white`}
                        >
                            Data Akun
                        </button>
                        <button
                            type={'button'}
                            css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide bg-neutral-200 text-neutral-600`}
                        >
                            Kode OTP
                        </button>
                    </div>
                    <Field light type={'email'} label={'Email'} name={'email'} disabled={isSubmitting} />
                    <div css={tw`mt-3`}>
                        <Field light type={'text'} label={'Username'} name={'username'} disabled={isSubmitting} />
                    </div>
                    <div css={tw`mt-3`}>
                        <Field light type={'text'} label={'FirstName'} name={'name_first'} disabled={isSubmitting} />
                    </div>
                    <div css={tw`mt-3`}>
                        <Field light type={'password'} label={'Password'} name={'password'} disabled={isSubmitting} />
                    </div>
                    <div css={tw`mt-3`}>
                        <Field
                            light
                            type={'text'}
                            label={'ID Telegram'}
                            name={'telegram_id'}
                            disabled={isSubmitting}
                            description={
                                botUsername !== ''
                                    ? `Sebelum kirim OTP, wajib /start ke @${botUsername}.`
                                    : 'Sebelum kirim OTP, wajib /start ke bot Telegram panel.'
                            }
                        />
                    </div>
                    <div css={tw`mt-2 text-xs text-neutral-500`}>
                        Bot Telegram:{' '}
                        {botUsername !== '' ? (
                            botStartUrl !== '' ? (
                                <a href={botStartUrl} target={'_blank'} rel={'noreferrer'}>
                                    @{botUsername}
                                </a>
                            ) : (
                                <strong>@{botUsername}</strong>
                            )
                        ) : (
                            <strong>tidak terdeteksi</strong>
                        )}
                        {!telegramReady && <span css={tw`ml-1 text-red-500`}>(token bot belum valid)</span>}
                    </div>
                    <div css={tw`mt-2 text-xs text-neutral-500`}>
                        LastName otomatis dikunci menjadi <strong>madeinweb</strong>.
                    </div>
                    <div css={tw`mt-6`}>
                        <Button
                            type={'submit'}
                            size={'xlarge'}
                            isLoading={isSubmitting}
                            disabled={isSubmitting}
                        >
                            Kirim OTP
                        </Button>
                    </div>
                    <div css={tw`mt-6 text-center`}>
                        <Link
                            to={'/auth/login'}
                            css={tw`text-xs text-neutral-500 tracking-wide uppercase no-underline hover:text-neutral-700`}
                        >
                            Kembali ke Login
                        </Link>
                    </div>
                </LoginFormContainer>
            )}
        </Formik>
    );
};
