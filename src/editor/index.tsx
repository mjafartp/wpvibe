/**
 * Editor entry point.
 *
 * Mounts the WPVibe theme editor React application into the
 * `#wpvibe-editor-root` container rendered by the PHP admin page.
 */

import { createRoot } from 'react-dom/client';
import { App } from '@/editor/App';

const container = document.getElementById('wpvibe-editor-root');

if (container) {
  // Add a body class so CSS can hide WP admin chrome
  document.body.classList.add('wpvibe-editor-active');

  const root = createRoot(container);
  root.render(<App />);
}
