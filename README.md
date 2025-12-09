<body>

<!-- Option A: use repo image (recommended). Place logo at ./assets/logo.png -->
<img src="./assets/logo.png" alt="Comment Beautifier logo" width="100" />

<!-- Option B: or use your WordPress-hosted image (uncomment and replace the URL)
<img src="https://your-site.com/wp-content/uploads/2025/11/logo.png" alt="Comment Beautifier logo" width="200" />
-->

<h1>Comment Beautifier</h1>

<p>Comment Beautifier helps you clean up spammy comments in bulk. It detects comments with links or spam-style text and lets you fix them with simple actions like Beautify, Remove Links, or Approve.</p>

<h2>Get it on wordpress</h2>
https://wordpress.org/plugins/comment-beautifier/

<h2>What This Plugin Does</h2>
<ul>
  <li>Detects comments that contain URLs or spam-style content.</li>
  <li>Removes or replaces unwanted links.</li>
  <li>Rewrites spam comments into simple, clean text.</li>
  <li>Replaces suspicious author names with normal names.</li>
  <li>Supports bulk selection for fast processing.</li>
  <li>Allows quick approval of cleaned comments.</li>
</ul>

<h2>How It Works</h2>
<ol>
  <li>Open the Comment Beautifier page inside your admin panel.</li>
  <li>Select the comments you want to review or fix.</li>
  <li>Choose an action such as Beautify, Remove Links, or Approve.</li>
  <li>The script sends the request to the backend and updates the comments instantly.</li>
</ol>

<h2>Files in This Plugin</h2>
<ul>
  <li><strong>scb.js</strong> – Controls UI, selections, previews, and AJAX requests.</li>
  <li><strong>scbp.php</strong> – Receives requests and updates comments on the server.</li>
  <li><strong>style.css</strong> – Optional styling. The tool works even without it.</li>
</ul>

<h2>Customization</h2>
<ul>
  <li>Edit replacement author names.</li>
  <li>Edit replacement comment messages.</li>
  <li>Choose how link cleaning should behave (remove or rewrite).</li>
  <li>Save your custom settings for later use.</li>
</ul>

<h2>Basic Usage</h2>
<ol>
  <li>Open the Beautifier page.</li>
  <li>Select spammy comments.</li>
  <li>Click the action you want.</li>
  <li>Check the updated comments.</li>
</ol>

<h2>Developer Notes</h2>

<ul>
  <li><strong>Front-end actions:</strong> scb.js handles selections, previews, filters, and sends AJAX requests. Make sure the HTML IDs and classes match what the script expects.</li>

  <li><strong>Backend logic:</strong> scbp.php receives an action name and a list of comment IDs. It validates the request, performs the update, and returns a JSON response.</li>

  <li><strong>Beautify system:</strong> The backend replaces author names and comment text using lists of predefined replacements. Developers can modify these lists freely.</li>

  <li><strong>Link detection:</strong> Comments containing URLs are flagged. The backend can remove links or rewrite them based on settings. This behavior is easy to change in the PHP logic.</li>

  <li><strong>Preview system:</strong> The preview is generated on the client side. No server request is needed for previewing before applying changes.</li>

  <li><strong>Filter and selection:</strong> The script tags each comment with flags like “has-link” so you can filter or bulk-select quickly. Developers can add more rules if needed.</li>

  <li><strong>Settings:</strong> Custom settings are saved using backend storage. Data must be sanitized before saving. Front-end loads these settings on page load.</li>

  <li><strong>Extending the tool:</strong> To add a new action, create a button, add a handler in scb.js, and implement the matching action in scbp.php. Both sides must use the same action key.</li>

  <li><strong>Validation:</strong> The backend must always validate comment IDs and user permissions before updating any data.</li>

  <li><strong>Security:</strong> Protect all AJAX endpoints with capability checks and nonces.</li>
</ul>

<h2>Security & Best Practices</h2>
<ul>
  <li>Check user permissions before performing actions.</li>
  <li>Validate and sanitize all inputs.</li>
  <li>Log bulk operations when possible for recovery.</li>
  <li>Optionally store original comment data for undo support.</li>
</ul>

<h2>License</h2>
<pre><code>
License GNU Affero General Public License v3.0
Copyright (c) 2025 Ujjwal Raj
Permission is hereby granted...
</code></pre>

</body>
