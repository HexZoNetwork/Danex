import React, { useEffect, useRef, useState } from 'react';
import Modal, { RequiredModalProps } from '@/components/elements/Modal';
import { Field, Form, Formik, FormikHelpers, useFormikContext } from 'formik';
import { Actions, useStoreActions, useStoreState } from 'easy-peasy';
import { object, string } from 'yup';
import debounce from 'debounce';
import FormikFieldWrapper from '@/components/elements/FormikFieldWrapper';
import InputSpinner from '@/components/elements/InputSpinner';
import getServers from '@/api/getServers';
import { Server } from '@/api/server/getServer';
import { ApplicationStore } from '@/state';
import { Link } from 'react-router-dom';
import styled from 'styled-components/macro';
import tw from 'twin.macro';
import Input from '@/components/elements/Input';
import { ip } from '@/lib/formatters';

type Props = RequiredModalProps;

interface Values {
    term: string;
}

const ServerResult = styled(Link)`
    ${tw`flex items-center p-4 rounded border-l-4 no-underline transition-all duration-150 border`};
    background: #0b0b10;
    border-color: rgba(139, 92, 246, 0.22);
    border-left-color: rgba(139, 92, 246, 0.42);
    box-shadow: 0 14px 34px rgba(0, 0, 0, 0.38);

    &:hover {
        border-color: rgba(139, 92, 246, 0.62);
        border-left-color: #8b5cf6;
        box-shadow: 0 18px 44px rgba(0, 0, 0, 0.5), 0 0 20px rgba(139, 92, 246, 0.14);
    }

    &:not(:last-of-type) {
        ${tw`mb-2`};
    }
`;

const SearchWatcher = ({ onTermChange }: { onTermChange: (term: string) => void }) => {
    const { values, submitForm } = useFormikContext<Values>();

    useEffect(() => {
        const cleanTerm = values.term.trim();
        onTermChange(cleanTerm);
        if (cleanTerm.length >= 3) {
            submitForm();
        }
    }, [values.term]);

    return null;
};

export default ({ ...props }: Props) => {
    const ref = useRef<HTMLInputElement>(null);
    const searchSeq = useRef(0);
    const isAdmin = useStoreState((state) => state.user.data!.rootAdmin);
    const [servers, setServers] = useState<Server[]>([]);
    const [termLength, setTermLength] = useState(0);
    const [hasSearched, setHasSearched] = useState(false);
    const { clearAndAddHttpError, clearFlashes } = useStoreActions(
        (actions: Actions<ApplicationStore>) => actions.flashes
    );

    const search = debounce(({ term }: Values, { setSubmitting }: FormikHelpers<Values>) => {
        clearFlashes('search');
        const cleanTerm = term.trim();
            const seq = (searchSeq.current += 1);
        setHasSearched(true);

        // if (ref.current) ref.current.focus();
        getServers({ query: cleanTerm, type: isAdmin ? 'admin-all' : undefined })
            .then((servers) => {
                if (seq === searchSeq.current) {
                    setServers(servers.items.filter((_, index) => index < 5));
                }
            })
            .catch((error) => {
                if (seq !== searchSeq.current) return;
                console.error(error);
                clearAndAddHttpError({ key: 'search', error });
            })
            .then(() => {
                if (seq === searchSeq.current) {
                    setSubmitting(false);
                    ref.current?.focus();
                }
            });
    }, 500);

    useEffect(() => {
        if (props.visible) {
            if (ref.current) ref.current.focus();
        }
    }, [props.visible]);

    // Formik does not support an innerRef on custom components.
    const InputWithRef = (props: any) => <Input autoFocus {...props} ref={ref} />;

    return (
        <Formik
            onSubmit={search}
            validationSchema={object().shape({
                term: string().min(3, 'Please enter at least three characters to begin searching.'),
            })}
            initialValues={{ term: '' } as Values}
        >
            {({ isSubmitting }) => (
                <Modal {...props}>
                    <Form>
                        <FormikFieldWrapper
                            name={'term'}
                            label={'Search term'}
                            description={'Enter a server name, uuid, or allocation to begin searching.'}
                        >
                            <SearchWatcher
                                onTermChange={(term) => {
                                    setTermLength(term.length);
                                    if (term.length < 3) {
                                        searchSeq.current += 1;
                                        setServers([]);
                                        setHasSearched(false);
                                    }
                                }}
                            />
                            <InputSpinner visible={isSubmitting}>
                                <Field as={InputWithRef} name={'term'} />
                            </InputSpinner>
                        </FormikFieldWrapper>
                    </Form>
                    {termLength > 0 && termLength < 3 && <p css={tw`mt-4 text-sm text-neutral-400`}>Type at least 3 characters to search.</p>}
                    {hasSearched && !isSubmitting && servers.length === 0 && <p css={tw`mt-4 text-sm text-neutral-400`}>No servers found.</p>}
                    {servers.length > 0 && (
                        <div css={tw`mt-6`}>
                            {servers.map((server) => (
                                <ServerResult
                                    key={server.uuid}
                                    to={`/server/${server.id}`}
                                    onClick={() => props.onDismissed()}
                                >
                                    <div css={tw`flex-1 mr-4`}>
                                        <p css={tw`text-sm`}>{server.name}</p>
                                        <p css={tw`mt-1 text-xs text-neutral-400`}>
                                            {server.allocations
                                                .filter((alloc) => alloc.isDefault)
                                                .map((allocation) => (
                                                    <span key={allocation.ip + allocation.port.toString()}>
                                                        {allocation.alias || ip(allocation.ip)}:{allocation.port}
                                                    </span>
                                                ))}
                                        </p>
                                    </div>
                                    <div css={tw`flex-none text-right`}>
                                        <span
                                            css={tw`text-xs py-1 px-2 rounded border`}
                                            style={{ background: '#111117', borderColor: 'rgba(139, 92, 246, 0.34)', color: '#ddd6fe' }}
                                        >
                                            {server.node}
                                        </span>
                                    </div>
                                </ServerResult>
                            ))}
                        </div>
                    )}
                </Modal>
            )}
        </Formik>
    );
};
