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

const segmentedStyle: React.CSSProperties = {
    background: '#0b0b10',
    borderColor: 'rgba(139, 92, 246, 0.28)',
    boxShadow: 'inset 0 1px 0 rgba(255, 255, 255, 0.04)',
};

const activeTabStyle: React.CSSProperties = {
    background: '#111117',
    color: '#ffffff',
    boxShadow: 'inset 0 -2px 0 #8b5cf6, 0 0 18px rgba(139, 92, 246, 0.12)',
};

const inactiveTabStyle: React.CSSProperties = {
    background: '#0b0b10',
    color: '#a3a3b2',
};

const helperPanelStyle: React.CSSProperties = {
    background: '#09090d',
    borderColor: 'rgba(139, 92, 246, 0.2)',
    boxShadow: 'inset 0 1px 0 rgba(255, 255, 255, 0.035)',
};

export default () => {
    const { clearFlashes, addFlash } = useFlash();
    const [requestToken, setRequestToken] = useState('');
    const [step, setStep] = useState<'register' | 'otp'>('register');
    const [registerPayload, setRegisterPayload] = useState<RegisterValues | null>(null);
    const [resending, setResending] = useState(false);
    const [botUsername, setBotUsername] = useState('');
    const [botStartUrl, setBotStartUrl] = useState('');
    const [telegramReady, setTelegramReady] = useState(true);
    const [requiredChannels, setRequiredChannels] = useState<string[]>([]);

    useEffect(() => {
        clearFlashes();
        getRegisterMeta()
            .then((meta) => {
                setBotUsername(meta.botUsername || '');
                setBotStartUrl(meta.botStartUrl || '');
                setTelegramReady(meta.telegramReady);
                if (meta.requiredChannels.length > 0) {
                    setRequiredChannels(meta.requiredChannels);
                }
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
                    <LoginFormContainer title={'Verify OTP'} css={tw`w-full mx-auto`} compact>
                        <div css={tw`mb-4 flex rounded-lg border overflow-hidden`} style={segmentedStyle}>
                            <button
                                type={'button'}
                                css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide`}
                                style={inactiveTabStyle}
                                onClick={() => setStep('register')}
                            >
                                Data Akun
                            </button>
                            <button
                                type={'button'}
                                css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide`}
                                style={activeTabStyle}
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
                        <div css={tw`mt-4 rounded-lg border p-3 text-xs text-neutral-400`} style={helperPanelStyle}>
                            OTP hanya valid untuk sesi pendaftaran ini. Jika bot belum mengirim kode, buka Telegram bot lalu tekan kirim ulang.
                        </div>
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
                <LoginFormContainer title={'Create Account'} css={tw`w-full mx-auto`} compact>
                    <div css={tw`mb-4 flex rounded-lg border overflow-hidden`} style={segmentedStyle}>
                        <button
                            type={'button'}
                            css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide`}
                            style={activeTabStyle}
                        >
                            Data Akun
                        </button>
                        <button
                            type={'button'}
                            css={tw`flex-1 py-2 text-xs font-semibold uppercase tracking-wide`}
                            style={inactiveTabStyle}
                        >
                            Kode OTP
                        </button>
                    </div>
                    <div css={tw`grid grid-cols-1 md:grid-cols-2 gap-3`}>
                        <Field light type={'email'} label={'Email'} name={'email'} disabled={isSubmitting} />
                        <Field light type={'text'} label={'Username'} name={'username'} disabled={isSubmitting} />
                        <Field light type={'text'} label={'FirstName'} name={'name_first'} disabled={isSubmitting} />
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
                    <div css={tw`mt-4 grid grid-cols-1 md:grid-cols-2 gap-3`}>
                        <div css={tw`rounded-lg border p-3 text-xs text-neutral-400`} style={helperPanelStyle}>
                            <div css={tw`uppercase tracking-widest text-neutral-500 mb-1`}>Telegram Bot</div>
                            {botUsername !== '' ? (
                                botStartUrl !== '' ? (
                                    <a href={botStartUrl} target={'_blank'} rel={'noreferrer'} css={tw`text-purple-200 no-underline hover:text-white`}>
                                        @{botUsername}
                                    </a>
                                ) : (
                                    <strong css={tw`text-purple-200`}>@{botUsername}</strong>
                                )
                            ) : (
                                <strong css={tw`text-red-300`}>tidak terdeteksi</strong>
                            )}
                            {!telegramReady && <span css={tw`block mt-1 text-red-400`}>token bot belum valid</span>}
                        </div>
                        <div css={tw`rounded-lg border p-3 text-xs text-neutral-400`} style={helperPanelStyle}>
                            <div css={tw`uppercase tracking-widest text-neutral-500 mb-1`}>Identity Lock</div>
                            LastName otomatis dikunci menjadi <strong css={tw`text-neutral-100`}>madeinweb</strong>.
                        </div>
                    </div>
                    {requiredChannels.length > 0 && (
                        <div css={tw`mt-3 rounded-lg border p-3 text-xs text-neutral-400`} style={helperPanelStyle}>
                            <span css={tw`uppercase tracking-widest text-neutral-500`}>Required Channel</span>{' '}
                            {requiredChannels.map((channel, index) => (
                                <React.Fragment key={channel}>
                                    {index > 0 && ', '}
                                    <a href={`https://t.me/${channel.replace(/^@/, '')}`} target={'_blank'} rel={'noreferrer'} css={tw`text-purple-200 no-underline hover:text-white`}>
                                        {channel}
                                    </a>
                                </React.Fragment>
                            ))}
                        </div>
                    )}
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
