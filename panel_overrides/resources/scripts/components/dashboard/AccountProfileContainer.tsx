import React, { useEffect, useRef, useState } from 'react';
import { Actions, State, useStoreActions, useStoreState } from 'easy-peasy';
import { Form, Formik, FormikHelpers } from 'formik';
import * as Yup from 'yup';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import { breakpoint } from '@/theme';
import { ApplicationStore } from '@/state';
import PageContentBlock from '@/components/elements/PageContentBlock';
import ContentBox from '@/components/elements/ContentBox';
import Field from '@/components/elements/Field';
import SpinnerOverlay from '@/components/elements/SpinnerOverlay';
import FlashMessageRender from '@/components/FlashMessageRender';
import { Button } from '@/components/elements/button';
import { getAccountProfile, updateAccountProfile, uploadAccountAvatar } from '@/api/account/profile';
import { httpErrorToHuman } from '@/api/http';

interface Values {
    username: string;
    email: string;
    name_first: string;
    name_last: string;
    avatar_url: string;
    birthday: string;
}

const schema = Yup.object().shape({
    username: Yup.string().required('Username is required.').max(191),
    email: Yup.string().email().required('Email is required.'),
    name_first: Yup.string().required('First name is required.').max(191),
    name_last: Yup.string().required('Last name is required.').max(191),
    avatar_url: Yup.string().nullable().url('Avatar must be a valid URL.'),
    birthday: Yup.string()
        .nullable()
        .matches(/^\d{4}-\d{2}-\d{2}$/, { message: 'Use YYYY-MM-DD format.', excludeEmptyString: true }),
});

const Layout = styled.div`
    ${tw`grid gap-6`};
    ${breakpoint('lg')`
        grid-template-columns: minmax(300px, 360px) 1fr;
        align-items: start;
    `}
`;

const ProfileHeader = styled.div`
    ${tw`bg-neutral-700 rounded-lg shadow-lg overflow-hidden`};
`;

const ProfileTop = styled.div`
    ${tw`px-6 py-8 bg-neutral-800 border-b border-neutral-600`};
`;

const AvatarWrap = styled.div`
    ${tw`w-24 h-24 rounded-full overflow-hidden border-4 border-black mx-auto`};
`;

const AvatarFallback = styled.div`
    ${tw`w-full h-full bg-neutral-600 text-neutral-100 flex items-center justify-center text-3xl font-bold`};
`;

const ProfileRows = styled.div`
    ${tw`px-5 py-4 space-y-4`};
`;

const ProfileRow = styled.div`
    ${tw`border-b border-neutral-800 pb-3`};

    &:last-child {
        ${tw`border-0 pb-0`};
    }
`;

export default () => {
    const user = useStoreState((state: State<ApplicationStore>) => state.user.data);
    const updateUserData = useStoreActions((actions: Actions<ApplicationStore>) => actions.user.updateUserData);
    const { clearFlashes, addFlash } = useStoreActions((actions: Actions<ApplicationStore>) => actions.flashes);

    const [loading, setLoading] = useState(true);
    const [avatarUploading, setAvatarUploading] = useState(false);
    const [avatarBroken, setAvatarBroken] = useState(false);
    const [avatarDragging, setAvatarDragging] = useState(false);
    const avatarInputRef = useRef<HTMLInputElement>(null);
    const [initialValues, setInitialValues] = useState<Values>({
        username: user?.username || '',
        email: user?.email || '',
        name_first: user?.firstName || '',
        name_last: user?.lastName || '',
        avatar_url: user?.avatarUrl || '',
        birthday: user?.birthday || '',
    });

    useEffect(() => {
        let isMounted = true;

        getAccountProfile()
            .then((profile) => {
                if (!isMounted) return;

                const values: Values = {
                    username: profile.username,
                    email: profile.email,
                    name_first: profile.firstName,
                    name_last: profile.lastName,
                    avatar_url: profile.avatarUrl,
                    birthday: profile.birthday,
                };

                setInitialValues(values);
                setAvatarBroken(false);
                updateUserData({
                    username: profile.username,
                    email: profile.email,
                    firstName: profile.firstName,
                    lastName: profile.lastName,
                    avatarUrl: profile.avatarUrl,
                    birthday: profile.birthday,
                });
            })
            .catch((error) => {
                addFlash({
                    key: 'account:profile',
                    type: 'error',
                    title: 'Error',
                    message: httpErrorToHuman(error),
                });
            })
            .then(() => {
                if (isMounted) {
                    setLoading(false);
                }
            });

        return () => {
            isMounted = false;
        };
    }, [addFlash, updateUserData]);

    const submit = (values: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes('account:profile');

        updateAccountProfile({
            username: values.username,
            email: values.email,
            name_first: values.name_first,
            name_last: values.name_last,
            avatar_url: values.avatar_url.trim() === '' ? null : values.avatar_url.trim(),
            birthday: values.birthday.trim() === '' ? null : values.birthday,
        })
            .then((profile) => {
                const nextValues: Values = {
                    username: profile.username,
                    email: profile.email,
                    name_first: profile.firstName,
                    name_last: profile.lastName,
                    avatar_url: profile.avatarUrl,
                    birthday: profile.birthday,
                };

                setInitialValues(nextValues);
                setAvatarBroken(false);
                updateUserData({
                    username: profile.username,
                    email: profile.email,
                    firstName: profile.firstName,
                    lastName: profile.lastName,
                    avatarUrl: profile.avatarUrl,
                    birthday: profile.birthday,
                });

                addFlash({
                    key: 'account:profile',
                    type: 'success',
                    message: 'Profile updated successfully.',
                });
            })
            .catch((error) => {
                addFlash({
                    key: 'account:profile',
                    type: 'error',
                    title: 'Error',
                    message: httpErrorToHuman(error),
                });
            })
            .then(() => setSubmitting(false));
    };

    const handleAvatarUpload = async (file?: File) => {
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            addFlash({
                key: 'account:profile',
                type: 'error',
                title: 'Error',
                message: 'Avatar harus berupa file image.',
            });
            return;
        }

        try {
            setAvatarUploading(true);
            const avatarUrl = await uploadAccountAvatar(file);
            if (!avatarUrl) {
                throw new Error('Upload gagal.');
            }

            setInitialValues((current) => ({ ...current, avatar_url: avatarUrl }));
            setAvatarBroken(false);
            updateUserData({ avatarUrl });
            addFlash({
                key: 'account:profile',
                type: 'success',
                message: 'Avatar uploaded successfully.',
            });
        } catch (error) {
            addFlash({
                key: 'account:profile',
                type: 'error',
                title: 'Error',
                message: httpErrorToHuman(error),
            });
        } finally {
            setAvatarUploading(false);
        }
    };

    return (
        <PageContentBlock title={'Edit Profile'}>
            <FlashMessageRender byKey={'account:profile'} css={tw`mb-4`} />

            <Layout>
                <ProfileHeader>
                    <ProfileTop>
                        <div
                            css={[tw`rounded-lg p-2 transition`, avatarDragging ? tw`ring-2 ring-cyan-500 bg-neutral-900/40` : undefined]}
                            onDragOver={(event) => {
                                event.preventDefault();
                                if (!avatarDragging) setAvatarDragging(true);
                            }}
                            onDragLeave={() => setAvatarDragging(false)}
                            onDrop={(event) => {
                                event.preventDefault();
                                setAvatarDragging(false);
                                handleAvatarUpload(event.dataTransfer?.files?.[0]);
                            }}
                        >
                            <AvatarWrap>
                                {initialValues.avatar_url && !avatarBroken ? (
                                    <img
                                        src={initialValues.avatar_url}
                                        alt={'Profile avatar'}
                                        css={tw`w-full h-full object-cover`}
                                        onError={() => {
                                            setAvatarBroken(true);
                                        }}
                                    />
                                ) : (
                                    <AvatarFallback>{(initialValues.username || 'U').charAt(0).toUpperCase()}</AvatarFallback>
                                )}
                            </AvatarWrap>
                            <p css={tw`text-center text-xs text-neutral-400 mt-2`}>Drag image ke avatar untuk upload cepat.</p>
                        </div>
                        <p css={tw`text-center text-lg text-neutral-100 font-semibold mt-4 break-words`}>
                            {`${initialValues.name_first} ${initialValues.name_last}`.trim() || initialValues.username || 'User'}
                        </p>
                        <p css={tw`text-center text-neutral-400 mt-1`}>online</p>
                    </ProfileTop>
                    <ProfileRows>
                        <ProfileRow>
                            <p css={tw`text-neutral-100 break-all`}>{initialValues.email || '-'}</p>
                            <p css={tw`text-neutral-400 text-sm mt-1`}>Email</p>
                        </ProfileRow>
                        <ProfileRow>
                            <p css={tw`text-cyan-400 break-all`}>@{initialValues.username || '-'}</p>
                            <p css={tw`text-neutral-400 text-sm mt-1`}>Username</p>
                        </ProfileRow>
                        <ProfileRow>
                            <p css={tw`text-neutral-100`}>{initialValues.birthday || '-'}</p>
                            <p css={tw`text-neutral-400 text-sm mt-1`}>Birthday</p>
                        </ProfileRow>
                    </ProfileRows>
                </ProfileHeader>

                <ContentBox title={'Profile Settings'} showLoadingOverlay={loading}>
                    <Formik onSubmit={submit} enableReinitialize validationSchema={schema} initialValues={initialValues}>
                        {({ isSubmitting, isValid }) => (
                            <>
                                <SpinnerOverlay visible={isSubmitting} />
                                <Form css={tw`m-0 grid gap-5`}>
                                    <Field id={'avatar_url'} name={'avatar_url'} label={'Photo URL'} placeholder={'https://...'} />
                                    <div css={tw`-mt-3`}>
                                        <Button
                                            type={'button'}
                                            size={'xsmall'}
                                            disabled={avatarUploading}
                                            onClick={() => avatarInputRef.current?.click()}
                                        >
                                            {avatarUploading ? 'Uploading avatar...' : 'Upload Avatar'}
                                        </Button>
                                        <input
                                            ref={avatarInputRef}
                                            type={'file'}
                                            accept={'image/jpeg,image/png,image/webp,image/gif'}
                                            css={tw`hidden`}
                                            onChange={(event) => {
                                                handleAvatarUpload(event.currentTarget.files?.[0]);
                                                event.currentTarget.value = '';
                                            }}
                                        />
                                    </div>
                                    <Field id={'username'} name={'username'} label={'Username'} />
                                    <Field id={'name_first'} name={'name_first'} label={'First Name'} />
                                    <Field id={'name_last'} name={'name_last'} label={'Last Name'} />
                                    <Field id={'email'} name={'email'} type={'email'} label={'Email'} />
                                    <Field id={'birthday'} name={'birthday'} type={'date'} label={'Birthday'} />

                                    <div css={tw`pt-2`}>
                                        <Button disabled={isSubmitting || !isValid}>Save Profile</Button>
                                    </div>
                                </Form>
                            </>
                        )}
                    </Formik>
                </ContentBox>
            </Layout>
        </PageContentBlock>
    );
};
