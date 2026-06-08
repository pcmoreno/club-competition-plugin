import { AdminHeader } from './AdminLayout';
import { Notice } from '../components/ui';

// Generic placeholder. Global admin settings (KNSB sync trigger, defaults, …)
// are a later pass — see dev/page-inventory.md (Admin sidebar / PARKED).
export function Settings() {
	return (
		<>
			<AdminHeader title="Settings" />
			<Notice>Not implemented yet.</Notice>
		</>
	);
}
