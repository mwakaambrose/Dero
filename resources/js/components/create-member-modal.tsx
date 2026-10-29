import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store as storeMember } from '@/routes/teams/members';
import type { RoleOption, Team } from '@/types';

type Props = {
    team: Team;
    availableRoles: RoleOption[];
    open: boolean;
    onOpenChange: (open: boolean) => void;
};

export default function CreateMemberModal({
    team,
    availableRoles,
    open,
    onOpenChange,
}: Props) {
    const [role, setRole] = useState<RoleOption['value']>('member');

    const handleOpenChange = (nextOpen: boolean) => {
        onOpenChange(nextOpen);

        if (!nextOpen) {
            setRole('member');
        }
    };

    return (
        <Dialog open={open} onOpenChange={handleOpenChange}>
            <DialogContent>
                <Form
                    key={String(open)}
                    {...storeMember.form(team.slug)}
                    className="space-y-6"
                    onSuccess={() => onOpenChange(false)}
                    resetOnSuccess={['password', 'password_confirmation']}
                >
                    {({ errors, processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Create a team member</DialogTitle>
                                <DialogDescription>
                                    Create an account with a password and add it
                                    to this team. Share the credentials with
                                    them directly.
                                </DialogDescription>
                            </DialogHeader>

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="member-name">Name</Label>
                                    <Input
                                        id="member-name"
                                        name="name"
                                        data-test="create-member-name"
                                        required
                                    />
                                    <InputError message={errors.name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="member-email">
                                        Email address
                                    </Label>
                                    <Input
                                        id="member-email"
                                        name="email"
                                        type="email"
                                        data-test="create-member-email"
                                        required
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="member-password">
                                        Password
                                    </Label>
                                    <PasswordInput
                                        id="member-password"
                                        name="password"
                                        autoComplete="new-password"
                                        data-test="create-member-password"
                                        required
                                    />
                                    <InputError message={errors.password} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="member-password-confirmation">
                                        Confirm password
                                    </Label>
                                    <PasswordInput
                                        id="member-password-confirmation"
                                        name="password_confirmation"
                                        autoComplete="new-password"
                                        required
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="member-role">Role</Label>
                                    <Select
                                        name="role"
                                        value={role}
                                        onValueChange={(value) =>
                                            setRole(
                                                value as RoleOption['value'],
                                            )
                                        }
                                    >
                                        <SelectTrigger
                                            id="member-role"
                                            className="w-full"
                                        >
                                            <SelectValue placeholder="Select a role" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {availableRoles.map((option) => (
                                                <SelectItem
                                                    key={option.value}
                                                    value={option.value}
                                                >
                                                    {option.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <InputError message={errors.role} />
                                </div>
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>

                                <Button
                                    type="submit"
                                    data-test="create-member-submit"
                                    disabled={processing}
                                >
                                    Create member
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
