import { createRoot } from 'react-dom/client';
import { SettingsApp } from './App';

const container = document.getElementById('wpvibe-settings-root');
if (container) {
  const root = createRoot(container);
  root.render(<SettingsApp />);
}
