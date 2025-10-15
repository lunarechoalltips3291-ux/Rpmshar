/* js/script.js
 *
 * Handles:
 * - Loading metadata.json and rendering the list of uploaded videos on index.html.
 * - Uploading files via XHR to show progress and handle JSON response from upload.php.
 *
 * NOTE: This script expects metadata.json to be available and updated by upload.php.
 */

(function(){
  const filesList = document.getElementById('filesList');
  const uploadForm = document.getElementById('uploadForm');
  const uploadBtn = document.getElementById('uploadBtn');
  const progressWrapper = document.getElementById('progressWrapper');
  const progressBar = document.getElementById('progressBar');
  const uploadResult = document.getElementById('uploadResult');

  // Format bytes nicely
  function formatBytes(bytes) {
    if (bytes === 0) return '0 B';
    const units = ['B','KB','MB','GB','TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return parseFloat((bytes / Math.pow(1024, i)).toFixed(2)) + ' ' + units[i];
  }

  // Load metadata.json and render list
  function loadList(){
    fetch('metadata.json?' + Date.now()).then(resp => {
      if (!resp.ok) throw new Error('No metadata');
      return resp.json();
    }).then(data => {
      filesList.innerHTML = '';
      if (!Array.isArray(data) || data.length === 0) {
        filesList.innerHTML = '<p class="hint">No videos uploaded yet.</p>';
        return;
      }
      // reverse chronological (latest first)
      data = data.slice().reverse();
      data.forEach(entry => {
        const card = document.createElement('div');
        card.className = 'file-card';
        const title = entry.title ? entry.title : entry.original_name;
        card.innerHTML = `<h3>${escapeHtml(title)}</h3>
          <p>${escapeHtml(entry.original_name)} • ${formatBytes(entry.size)} • uploaded ${new Date(entry.uploaded_at).toLocaleString()}</p>
          <div class="file-actions">
            <a href="preview.php?id=${encodeURIComponent(entry.id)}" target="_blank">Preview</a>
            <a href="download.php?id=${encodeURIComponent(entry.id)}">Download</a>
          </div>`;
        filesList.appendChild(card);
      });
    }).catch(err => {
      filesList.innerHTML = '<p class="hint">No metadata available. Upload files to populate the list.</p>';
    });
  }

  // Escape HTML to avoid injection in DOM
  function escapeHtml(str) {
    if (!str) return '';
    return String(str).replace(/[&<>"']/g, function (s) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[s];
    });
  }

  // Handle upload via XHR to show progress
  uploadForm.addEventListener('submit', function(e){
    e.preventDefault();
    const fileInput = document.getElementById('videoInput');
    if (!fileInput.files || fileInput.files.length === 0) {
      alert('Please choose a file to upload.');
      return;
    }
    const formData = new FormData(uploadForm);

    const xhr = new XMLHttpRequest();
    xhr.open('POST', uploadForm.action, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.addEventListener('progress', function(evt){
      if (evt.lengthComputable) {
        const percent = Math.round(evt.loaded / evt.total * 100);
        progressWrapper.style.display = 'block';
        progressBar.style.width = percent + '%';
      }
    });

    xhr.onreadystatechange = function(){
      if (xhr.readyState === 4) {
        progressWrapper.style.display = 'none';
        progressBar.style.width = '0%';
        if (xhr.status >= 200 && xhr.status < 300) {
          try {
            const res = JSON.parse(xhr.responseText);
            if (res.success) {
              uploadResult.innerHTML = `<div class="success">Upload successful. <a href="${res.preview}" target="_blank">Preview</a> • <a href="${res.download}">Download</a></div>`;
              // refresh list
              loadList();
              uploadForm.reset();
            } else {
              uploadResult.innerHTML = `<div class="error">Error: ${res.error || 'Upload failed'}</div>`;
            }
          } catch (err) {
            uploadResult.innerHTML = `<div class="error">Upload failed (invalid response)</div>`;
          }
        } else {
          uploadResult.innerHTML = `<div class="error">Upload failed (HTTP ${xhr.status})</div>`;
        }
      }
    };

    xhr.send(formData);
  });

  // Initial load
  loadList();
})();
