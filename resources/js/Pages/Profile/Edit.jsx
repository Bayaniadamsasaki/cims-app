import CimsLayout from '@/Layouts/CimsLayout';
import { Head } from '@inertiajs/react';
import DeleteUserForm from './Partials/DeleteUserForm';
import UpdatePasswordForm from './Partials/UpdatePasswordForm';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm';

export default function Edit({ mustVerifyEmail, status }) {
    return (
        <CimsLayout
            header={
                <div>
                    <h2 className="text-2xl font-bold tracking-tight text-slate-900">
                        Pengaturan Profil
                    </h2>
                    <p className="text-sm text-slate-500">
                        Kelola informasi akun dan kata sandi Anda.
                    </p>
                </div>
            }
        >
            <Head title="Pengaturan Profil" />

            <div className="py-8">
                <div className="mx-auto max-w-7xl space-y-6 sm:px-6 lg:px-8">
                    <div className="bg-brand-card border border-brand-border p-6 shadow-xl sm:rounded-2xl sm:p-8">
                        <UpdateProfileInformationForm
                            mustVerifyEmail={mustVerifyEmail}
                            status={status}
                            className="max-w-xl"
                        />
                    </div>

                    <div className="bg-brand-card border border-brand-border p-6 shadow-xl sm:rounded-2xl sm:p-8">
                        <UpdatePasswordForm className="max-w-xl" />
                    </div>

                    <div className="bg-brand-card border border-brand-border p-6 shadow-xl sm:rounded-2xl sm:p-8">
                        <DeleteUserForm className="max-w-xl" />
                    </div>
                </div>
            </div>
        </CimsLayout>
    );
}
