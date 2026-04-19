import React, { lazy } from 'react';

interface RouteDefinition {
    path: string;
    name: string | undefined;
    component: React.ComponentType;
    exact?: boolean;
}

const AccountOverviewContainer = lazy(() => import('@/components/dashboard/AccountOverviewContainer'));
const AccountApiContainer = lazy(() => import('@/components/dashboard/AccountApiContainer'));
const AccountSSHContainer = lazy(() => import('@/components/dashboard/ssh/AccountSSHContainer'));
const ActivityLogContainer = lazy(() => import('@/components/dashboard/activity/ActivityLogContainer'));

const accountRoutes: RouteDefinition[] = [
    {
        path: '/',
        name: 'Account',
        component: AccountOverviewContainer,
        exact: true,
    },
    {
        path: '/api',
        name: 'API Credentials',
        component: AccountApiContainer,
    },
    {
        path: '/ssh',
        name: 'SSH Keys',
        component: AccountSSHContainer,
    },
    {
        path: '/activity',
        name: 'Activity',
        component: ActivityLogContainer,
    },
];

export default accountRoutes;
