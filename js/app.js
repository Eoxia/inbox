/**
 * app.js - Frontend Logic for Inbox Module
 * Implements Context JS principles for real-time interactions
 */

document.addEventListener('DOMContentLoaded', () => {
	console.log("Inbox module initialized");

	// Parse "Name <email>, ..." into [{name, email}]
	const parseRecipients = (str) => {
		if (!str) return [];
		const parts = [];
		const re = /(?:[^,<]*<[^>]*>|[^,])+/g;
		let m;
		while ((m = re.exec(str)) !== null) {
			const part = m[0].trim();
			const angle = part.match(/^(.*?)\s*<([^>]+)>$/);
			if (angle) {
				const name = angle[1].trim().replace(/^"|"$/g, '');
				const email = angle[2].trim();
				parts.push({ name: name || email, email });
			} else if (part) {
				parts.push({ name: part, email: part });
			}
		}
		return parts;
	};

	// Relative / contextual date for the email list
	const formatDate = (dateStr) => {
		if (!dateStr) return '';
		const date = new Date(dateStr.replace(' ', 'T'));
		if (isNaN(date)) return dateStr;
		const now   = new Date();
		const diffMs  = now - date;
		const diffMin = Math.floor(diffMs / 60000);
		const diffH   = Math.floor(diffMs / 3600000);

		if (diffMin < 1)  return 'À l\'instant';
		if (diffMin < 60) return `${diffMin} min`;
		// Same calendar day
		if (date.toDateString() === now.toDateString()) {
			return date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
		}
		// Yesterday
		const yest = new Date(now); yest.setDate(yest.getDate() - 1);
		if (date.toDateString() === yest.toDateString()) {
			return 'Hier ' + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
		}
		// Same year
		if (date.getFullYear() === now.getFullYear()) {
			return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' });
		}
		// Older
		return date.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' });
	};

	// Full date for the view panel header
	const formatDateFull = (dateStr) => {
		if (!dateStr) return '';
		const date = new Date(dateStr.replace(' ', 'T'));
		if (isNaN(date)) return dateStr;
		return date.toLocaleDateString('fr-FR', {
			weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
		}) + ' à ' + date.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
	};

	const renderRecipients = (str) => {
		return parseRecipients(str).map(r => {
			const safeEmail = r.email.replace(/"/g, '&quot;').replace(/</g, '&lt;');
			const safeName  = r.name.replace(/</g, '&lt;').replace(/>/g, '&gt;');
			return r.name !== r.email
				? `<span title="${safeEmail}" style="cursor:default;border-bottom:1px dotted #94a3b8;">${safeName}</span>`
				: `<span>${safeEmail}</span>`;
		}).join(', ');
	};

	// Simple event listeners for the mockup interactions
	
	// 1. Mailbox navigation
	const folders = document.querySelectorAll('.folder-list li');
	folders.forEach(folder => {
		folder.addEventListener('click', (e) => {
			folders.forEach(f => f.classList.remove('active'));
			e.currentTarget.classList.add('active');
			// In real version, fetch emails via Ajax
			console.log("Switching folder...");
		});
	});

	// Current selected email state
	let currentEmail = null;
	let currentEmailBody = '';
	let currentFolder = 'INBOX';
	let trashFolder = null; // detected from folder list
	let currentIframe = null;
	let currentAccountId = null;

	// Reset the view panel to an empty state without hiding it (keeps layout stable)
	const clearViewPanel = () => {
		currentEmail = null;
		currentEmailBody = '';
		currentIframe = null;
		document.querySelector('.email-view-subject').innerText = '';
		document.querySelector('.sender-avatar').textContent = '';
		document.querySelector('.sender-name').innerHTML = '';
		document.querySelector('.sender-email').innerHTML = '';
		document.querySelector('.email-view-date').innerText = '';
		document.querySelector('.email-view-body').innerHTML = '';
		document.getElementById('remote-images-banner').style.display = 'none';
		document.getElementById('reply-form-container').style.display = 'none';
		document.getElementById('email-view-tags').querySelectorAll('.tag-dynamic').forEach(t => t.remove());
		document.getElementById('email-attachment-bar')?.remove();
	};

	// Replace remote src attributes with data-original-src to block external image loading
	const blockRemoteImages = (html) => {
		return html.replace(/\bsrc=(["'])(https?:\/\/[^"'>\s]+)\1/gi, 'data-original-src=$1$2$1');
	};

	// Pagination state
	let emailPage = 0;
	const EMAIL_PAGE_SIZE = 50;
	let emailsLoading = false;
	let emailsHasMore = false;
	let scrollObserver = null; // IntersectionObserver for infinite scroll

	// Fetch folders for a given account
	const fetchFolders = (accountId) => {
		trashFolder = null;
		const ul = document.getElementById('dynamic-folder-list');
		ul.innerHTML = '<li><i class="fa fa-spin fa-spinner"></i></li>';

		fetch('../../custom/inbox/ajax/get_folders.php?account_id=' + encodeURIComponent(accountId))
			.then(response => response.json())
			.then(data => {
				ul.innerHTML = '';

				if (data.error) {
					ul.innerHTML = '<li><i class="fa fa-exclamation-triangle"></i> ' + data.error + '</li>';
					return;
				}

				currentFolder = 'INBOX';

				data.data.forEach(f => {
					if (f.type === 'trash' && !trashFolder) trashFolder = f.id;

					const li = document.createElement('li');

					let icon = 'fa-folder';
					if (f.type == 'inbox') icon = 'fa-inbox';
					else if (f.type == 'sent') icon = 'fa-paper-plane';
					else if (f.type == 'drafts') icon = 'fa-file';
					else if (f.type == 'trash') icon = 'fa-trash';
					else if (f.type == 'archive') icon = 'fa-archive';

					li.innerHTML = '<i class="fa ' + icon + '"></i><span class="folder-name"> ' + f.name + '</span>';
					li.title = f.name;
					li.dataset.id = f.id;

					if (f.id == currentFolder || (currentFolder == 'INBOX' && f.type == 'inbox')) {
						li.className = 'active';
						currentFolder = f.id;
					}

					li.addEventListener('click', () => {
						document.querySelectorAll('#dynamic-folder-list li').forEach(el => el.classList.remove('active'));
						li.classList.add('active');
						currentFolder = f.id;
						document.getElementById('reply-form-container').style.display = 'none';
						fetchEmails(true, true);
					});

					ul.appendChild(li);
				});

				fetchEmails(true, true);
			})
			.catch(err => {
				console.error("Error fetching folders:", err);
				ul.innerHTML = '<li><i class="fa fa-exclamation-triangle"></i> Erreur réseau</li>';
			});
	};

	// Fetch and display the list of mailbox accounts in the sidebar
	const fetchUnreadCounts = () => {
		fetch('../../custom/inbox/ajax/get_unread_counts.php')
			.then(r => r.json())
			.then(data => {
				if (!data.data) return;
				Object.entries(data.data).forEach(([id, count]) => {
					const li = document.querySelector('#dynamic-account-list li[data-id="' + id + '"]');
					if (!li) return;
					let badge = li.querySelector('.account-unread-badge');
					if (count > 0) {
						if (!badge) {
							badge = document.createElement('span');
							badge.className = 'badge account-unread-badge';
							li.appendChild(badge);
						}
						badge.textContent = count > 99 ? '99+' : count;
					} else if (badge) {
						badge.remove();
					}
				});
			})
			.catch(() => {});
	};

	const fetchAccounts = () => {
		fetch('../../custom/inbox/ajax/get_accounts.php')
			.then(r => r.json())
			.then(data => {
				const ul = document.getElementById('dynamic-account-list');
				ul.innerHTML = '';

				if (data.error || !data.data || data.data.length === 0) {
					ul.innerHTML = '<li><i class="fa fa-exclamation-triangle"></i> Aucune boîte configurée</li>';
					return;
				}

				data.data.forEach((account, idx) => {
					const li = document.createElement('li');
					li.className = 'account-item';
					li.dataset.id = account.rowid;
					li.innerHTML = '<i class="fa fa-inbox"></i><span class="account-name"> ' + account.label + '</span>';
					li.title = account.email;

					li.addEventListener('click', () => {
						document.querySelectorAll('#dynamic-account-list li').forEach(el => el.classList.remove('active'));
						li.classList.add('active');
						currentAccountId = account.rowid;
						clearViewPanel();
						clearEmailList();
						fetchFolders(currentAccountId);
					});

					ul.appendChild(li);

					// Auto-select first account
					if (idx === 0) {
						li.classList.add('active');
						currentAccountId = account.rowid;
						fetchFolders(currentAccountId);
					}
				});

				// Load unread badges asynchronously after accounts are rendered
				fetchUnreadCounts();
			})
			.catch(err => {
				console.error("Error fetching accounts:", err);
				document.getElementById('dynamic-account-list').innerHTML = '<li><i class="fa fa-exclamation-triangle"></i> Erreur réseau</li>';
			});
	};

	// Build a single email list item element
	const buildEmailEl = (email) => {
		const el = document.createElement('div');
		el.className = 'email-item ' + (email.seen ? '' : 'unread');
		el.dataset.uid = email.uid;
		el.innerHTML = `
			<div class="email-item-header">
				<span class="email-sender">${email.from}</span>
				<span class="email-date" title="${email.date}">${formatDate(email.date)}</span>
			</div>
			<div class="email-subject">${email.subject}</div>
			<div class="email-item-actions">
				<button class="email-action-btn btn-item-seen" title="${email.seen ? 'Marquer non lu' : 'Marquer lu'}">
					<i class="fa ${email.seen ? 'fa-envelope' : 'fa-envelope-open'}"></i>
				</button>
				<button class="email-action-btn btn-item-trash" title="Mettre à la corbeille">
					<i class="fa fa-trash"></i>
				</button>
			</div>
		`;

		// Toggle read/unread
		el.querySelector('.btn-item-seen').addEventListener('click', (e) => {
			e.stopPropagation();
			const isUnread = el.classList.contains('unread');
			el.classList.toggle('unread', !isUnread);
			const btn = e.currentTarget;
			const icon = btn.querySelector('i');
			icon.className = isUnread ? 'fa fa-envelope' : 'fa fa-envelope-open';
			btn.title = isUnread ? 'Marquer non lu' : 'Marquer lu';
			const fd = new URLSearchParams({ uid: email.uid, folder: currentFolder, account_id: currentAccountId, seen: isUnread ? 1 : 0 });
			fetch('../../custom/inbox/ajax/mark_seen.php', { method: 'POST', body: fd })
				.then(() => fetchUnreadCounts())
				.catch(() => {});
		});

		// Trash
		el.querySelector('.btn-item-trash').addEventListener('click', (e) => {
			e.stopPropagation();
			const fd = new FormData();
			fd.append('uid', email.uid);
			fd.append('folder', currentFolder);
			fd.append('account_id', currentAccountId);
			if (trashFolder) fd.append('trash_folder', trashFolder);
			fetch('../../custom/inbox/ajax/trash_email.php', { method: 'POST', body: fd })
				.then(r => r.json())
				.then(data => {
					if (data.success) {
						el.remove();
						if (currentEmail && String(currentEmail.uid) === String(email.uid)) clearViewPanel();
						fetchUnreadCounts();
					}
				})
				.catch(() => {});
		});

		el.addEventListener('click', (e) => {
			document.querySelectorAll('.email-item').forEach(em => em.classList.remove('active'));
			e.currentTarget.classList.add('active');
			if (e.currentTarget.classList.contains('unread')) {
				e.currentTarget.classList.remove('unread');
				const fd = new URLSearchParams({ uid: email.uid, folder: currentFolder, account_id: currentAccountId });
				fetch('../../custom/inbox/ajax/mark_seen.php', { method: 'POST', body: fd }).catch(() => {});
			}

			currentEmail = email;

			document.getElementById('reply-form-container').style.display = 'none';

			document.querySelector('.email-view-subject').innerText = email.subject;
			document.querySelector('.email-view-date').innerText = formatDateFull(email.date);

			// Sender: show display name with email tooltip
			const fromParsed = parseRecipients(email.from);
			const fromHtml = fromParsed.length
				? fromParsed.map(r => {
					const safeEmail = r.email.replace(/"/g, '&quot;').replace(/</g, '&lt;');
					const safeName  = r.name.replace(/</g, '&lt;').replace(/>/g, '&gt;');
					return r.name !== r.email
						? `<span title="${safeEmail}" style="cursor:default;border-bottom:1px dotted #94a3b8;">${safeName}</span>`
						: `<span>${safeEmail}</span>`;
				}).join(', ')
				: email.from;
			document.querySelector('.sender-name').innerHTML = fromHtml + ' <a href="#" class="link-erp"><i class="fa fa-user"></i> Contact</a>';

			// Recipients
			const senderEmailEl = document.querySelector('.sender-email');
			if (senderEmailEl) {
				let recipientHtml = '';
				if (email.to) recipientHtml += '<span style="color:#64748b;">À :</span> ' + renderRecipients(email.to);
				if (email.cc) recipientHtml += '<br><span style="color:#64748b;">Cc :</span> ' + renderRecipients(email.cc);
				senderEmailEl.innerHTML = recipientHtml;
			}

			// Sender avatar initials
			const avatarEl = document.querySelector('.sender-avatar');
			if (avatarEl) {
				const namePart = email.from.replace(/<[^>]+>/, '').trim() || email.from;
				const words = namePart.trim().split(/\s+/);
				const initials = words.length >= 2
					? (words[0][0] + words[words.length - 1][0]).toUpperCase()
					: namePart.slice(0, 2).toUpperCase();
				avatarEl.textContent = initials;
			}

			const bodyContainer = document.querySelector('.email-view-body');
			bodyContainer.innerHTML = '<div style="text-align:center; padding: 40px; color: #888;"><i class="fa fa-spinner fa-spin fa-2x"></i><br>Chargement...</div>';

			// Clear previous attachment list, tags and comments
			const existingAttachBar = document.getElementById('email-attachment-bar');
			if (existingAttachBar) existingAttachBar.remove();
			const tpw = document.getElementById('tag-picker-wrapper');
			if (tpw) tpw.style.display = 'none';
			loadMessageTags(email.message_id);
			loadComments(email.uid, currentFolder, email.message_id);

			fetch('../../custom/inbox/ajax/get_email_body.php?uid=' + email.uid + '&folder=' + encodeURIComponent(currentFolder) + '&account_id=' + encodeURIComponent(currentAccountId))
				.then(res => {
					if (!res.ok) throw new Error("HTTP error " + res.status);
					return res.json();
				})
				.then(bodyData => {
					if (bodyData.error) {
						bodyContainer.innerHTML = '<div style="color:red; padding:20px;">Erreur lors du chargement du corps: ' + bodyData.error + '</div>';
						currentEmailBody = '';
					} else {
						currentEmailBody = bodyData.body;
						// Render HTML email in an isolated iframe to prevent CSS/JS conflicts
						const iframe = document.createElement('iframe');
						iframe.style.cssText = 'width:100%; border:none; display:block; min-height:200px;';
						iframe.setAttribute('sandbox', 'allow-same-origin allow-popups');
						bodyContainer.innerHTML = '';
						bodyContainer.appendChild(iframe);
						currentIframe = iframe;

						// Block remote images if requested
						const imgBanner = document.getElementById('remote-images-banner');
						let displayBody = bodyData.body;
						if (bodyData.block_images) {
							const blocked = blockRemoteImages(bodyData.body);
							if (blocked !== bodyData.body) {
								displayBody = blocked;
								imgBanner.style.display = 'flex';
							} else {
								imgBanner.style.display = 'none';
							}
						} else {
							imgBanner.style.display = 'none';
						}

						iframe.srcdoc = displayBody;
						iframe.addEventListener('load', () => {
							try {
								const h = iframe.contentDocument.documentElement.scrollHeight;
								iframe.style.height = Math.max(200, h) + 'px';
							} catch (e) {}
						});
					}
					// Render attachment list
					if (bodyData.attachments && bodyData.attachments.length > 0) {
						const bar = document.createElement('div');
						bar.id = 'email-attachment-bar';
						bar.style.cssText = 'padding: 10px 20px; border-top: 1px solid #e2e8f0; background: #f8fafc; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;';
						bar.innerHTML = '<span style="color:#64748b; font-size:0.85em; margin-right:4px;"><i class="fa fa-paperclip"></i> Pièces jointes :</span>';
						bodyData.attachments.forEach(att => {
							const kb = att.size > 0 ? ' (' + (att.size > 1048576 ? (att.size / 1048576).toFixed(1) + ' Mo' : Math.ceil(att.size / 1024) + ' Ko') + ')' : '';
							const url = '../../custom/inbox/ajax/get_attachment.php'
								+ '?uid=' + encodeURIComponent(email.uid)
								+ '&partno=' + encodeURIComponent(att.partno)
								+ '&encoding=' + encodeURIComponent(att.encoding || 0)
								+ '&folder=' + encodeURIComponent(currentFolder)
								+ '&filename=' + encodeURIComponent(att.filename);
							const chip = document.createElement('a');
							chip.href = url;
							chip.target = '_blank';
							chip.style.cssText = 'display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:#fff; border:1px solid #cbd5e1; border-radius:20px; font-size:0.82em; color:#1e293b; text-decoration:none; white-space:nowrap;';
							chip.innerHTML = '<i class="fa fa-file-o"></i> ' + att.filename + '<span style="color:#94a3b8;">' + kb + '</span>';
							bar.appendChild(chip);
						});
						bodyContainer.parentElement.insertBefore(bar, bodyContainer.nextSibling);
					}
				})
				.catch(err => {
					console.error("Fetch body error:", err);
					bodyContainer.innerHTML = '<div style="color:red; padding:20px;">Erreur réseau lors du chargement du corps. (Voir console)</div>';
				});
		});
		return el;
	};

	// Clear email list keeping the sentinel intact
	const clearEmailList = () => {
		const container = document.getElementById('email-list-container');
		Array.from(container.children).forEach(child => {
			if (child.id !== 'email-list-sentinel') child.remove();
		});
	};

	// Fetch emails from IMAP via AJAX.
	// reset=true  : replace list  (folderSwitch=true → clear immediately, false → silent background)
	// reset=false : append next page
	const fetchEmails = (reset = true, folderSwitch = false) => {
		if (emailsLoading) return;
		emailsLoading = true;

		const container = document.getElementById('email-list-container');
		const sentinel = document.getElementById('email-list-sentinel');
		const syncIcon = document.querySelector('.inbox-panel-header .fa-sync');

		if (reset) {
			emailPage = 0;
			emailsHasMore = false;

			if (folderSwitch) {
				// Folder change: user expects an immediate blank slate
				clearEmailList();
				const spinner = document.createElement('div');
				spinner.id = 'email-folder-spinner';
				spinner.style.cssText = 'padding: 20px; text-align: center; color: #64748b;';
				spinner.innerHTML = '<i class="fa fa-spinner fa-spin fa-2x"></i>';
				container.insertBefore(spinner, sentinel);
			} else {
				// Same-folder refresh: spin the sync button, touch nothing else
				if (syncIcon) syncIcon.classList.add('fa-spin');
			}
		} else {
			const loader = document.createElement('div');
			loader.id = 'email-page-loader';
			loader.style.cssText = 'padding: 12px; text-align: center; color: #64748b; font-size: 0.9em;';
			loader.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Chargement...';
			container.insertBefore(loader, sentinel);
		}

		const url = '../../custom/inbox/ajax/get_emails.php?folder=' + encodeURIComponent(currentFolder)
			+ '&offset=' + (emailPage * EMAIL_PAGE_SIZE)
			+ '&account_id=' + encodeURIComponent(currentAccountId);

		// Snapshot the ordered UIDs and selected UID before clearing
		const previousUid  = currentEmail ? String(currentEmail.uid) : null;
		const previousUids = Array.from(container.querySelectorAll('.email-item[data-uid]'))
			.map(el => el.dataset.uid);

		const resetUI = () => {
			if (syncIcon) syncIcon.classList.remove('fa-spin');
			document.getElementById('email-folder-spinner')?.remove();
			document.getElementById('email-page-loader')?.remove();
		};

		fetch(url)
			.then(response => response.json())
			.then(data => {
				emailsLoading = false;
				resetUI();

				if (data.error) {
					if (reset) {
						clearEmailList();
						const errDiv = document.createElement('div');
						errDiv.style.cssText = 'padding: 20px; color: red;';
						errDiv.textContent = data.error;
						container.insertBefore(errDiv, sentinel);
					}
					return;
				}

				if (reset) clearEmailList();

				if (data.data && data.data.length > 0) {
					data.data.forEach(email => {
						container.insertBefore(buildEmailEl(email), sentinel);
					});
				} else if (reset) {
					const emptyDiv = document.createElement('div');
					emptyDiv.style.cssText = 'padding: 20px; text-align: center; color: #64748b;';
					emptyDiv.textContent = 'Aucun email trouvé.';
					container.insertBefore(emptyDiv, sentinel);
				}

				emailsHasMore = data.has_more;
				emailPage++;
				if (scrollObserver) {
					scrollObserver.unobserve(emailSentinel);
					if (emailsHasMore) scrollObserver.observe(emailSentinel);
				}

				if (folderSwitch && data.data && data.data.length > 0) {
					// Folder switch: auto-select first email
					container.querySelector('.email-item')?.click();
				} else if (!folderSwitch) {
					const allItems = Array.from(container.querySelectorAll('.email-item'));
					const prevItem = previousUid
						? container.querySelector(`.email-item[data-uid="${previousUid}"]`)
						: null;

					if (prevItem) {
						// Email still present: restore highlight silently (no re-open)
						prevItem.classList.add('active');
					} else if (allItems.length > 0) {
						// Email gone: find the nearest neighbor from the old ordered list
						const prevIndex = previousUids.indexOf(previousUid);
						const newUidSet = new Set(allItems.map(el => el.dataset.uid));
						let candidate = null;

						// Search forward from old position
						for (let i = prevIndex + 1; i < previousUids.length && !candidate; i++) {
							if (newUidSet.has(previousUids[i])) candidate = previousUids[i];
						}
						// Then backward
						for (let i = prevIndex - 1; i >= 0 && !candidate; i--) {
							if (newUidSet.has(previousUids[i])) candidate = previousUids[i];
						}

						const target = candidate
							? container.querySelector(`.email-item[data-uid="${candidate}"]`)
							: allItems[0];
						if (target) target.click();
					} else {
						// List is now empty: clear the view panel
						clearViewPanel();
					}
				}
			})
			.catch(err => {
				emailsLoading = false;
				resetUI();
				if (reset) {
					clearEmailList();
					const errDiv = document.createElement('div');
					errDiv.style.cssText = 'padding: 20px; color: red;';
					errDiv.textContent = 'Erreur réseau lors de la synchronisation.';
					container.insertBefore(errDiv, sentinel);
				}
				console.error(err);
			});
	};

	// Add sentinel div so the loader always appears at the bottom
	const emailSentinel = document.createElement('div');
	emailSentinel.id = 'email-list-sentinel';
	emailSentinel.style.height = '1px';
	document.getElementById('email-list-container').appendChild(emailSentinel);

	// Infinite scroll via IntersectionObserver.
	// Re-armed after each page load so it fires again when sentinel remains visible
	// (handles both "content doesn't fill the container" and "user scrolls to bottom").
	scrollObserver = new IntersectionObserver((entries) => {
		if (entries[0].isIntersecting && !emailsLoading && emailsHasMore) {
			fetchEmails(false);
		}
	}, { threshold: 0 });
	scrollObserver.observe(emailSentinel);

	// Sidebar collapse toggle
	const sidebarEl  = document.getElementById('panel-folders');
	const toggleBtn  = document.getElementById('btn-sidebar-toggle');
	const toggleIcon = toggleBtn ? toggleBtn.querySelector('i') : null;

	const setSidebarCollapsed = (collapsed) => {
		if (collapsed) {
			sidebarEl.classList.add('collapsed');
			if (toggleIcon) { toggleIcon.classList.replace('fa-chevron-left', 'fa-chevron-right'); }
			if (toggleBtn)  toggleBtn.title = 'Agrandir la barre latérale';
		} else {
			sidebarEl.classList.remove('collapsed');
			if (toggleIcon) { toggleIcon.classList.replace('fa-chevron-right', 'fa-chevron-left'); }
			if (toggleBtn)  toggleBtn.title = 'Réduire la barre latérale';
		}
		try { localStorage.setItem('inbox_sidebar_collapsed', collapsed ? '1' : '0'); } catch (e) {}
	};

	if (toggleBtn) {
		toggleBtn.addEventListener('click', () => setSidebarCollapsed(!sidebarEl.classList.contains('collapsed')));
	}

	// Restore state
	try {
		if (localStorage.getItem('inbox_sidebar_collapsed') === '1') setSidebarCollapsed(true);
	} catch (e) {}

	// Trigger fetch on load
	fetchAccounts();

	// Wire up the manual refresh button
	const btnSync = document.querySelector('.inbox-panel-header .fa-sync');
	if (btnSync) {
		btnSync.parentElement.addEventListener('click', () => fetchEmails());
	}

	// Auto-refresh: reload email list at the configured interval (skip if composing)
	const refreshInterval = typeof inboxRefreshInterval !== 'undefined' ? inboxRefreshInterval : 0;
	if (refreshInterval > 0) {
		setInterval(() => {
			const replyOpen = document.getElementById('reply-form-container').style.display !== 'none';
			if (!replyOpen) {
				fetchEmails();
				fetchUnreadCounts();
			}
		}, refreshInterval * 1000);
	}

	// Trash button
	const btnTrashEl = document.querySelector('.header-actions .fa-trash');
	if (btnTrashEl) {
		btnTrashEl.parentElement.addEventListener('click', () => {
			if (!currentEmail) return;

			const formData = new URLSearchParams();
			formData.append('uid', currentEmail.uid);
			formData.append('folder', currentFolder);
			formData.append('account_id', currentAccountId);
			if (trashFolder && trashFolder !== currentFolder) {
				formData.append('trash_folder', trashFolder);
			}

			fetch('../../custom/inbox/ajax/trash_email.php', {
				method: 'POST',
				body: formData
			})
			.then(res => res.json())
			.then(data => {
				if (data.error) {
					setEventMessage(data.error, 'errors');
					console.error('Trash error:', data.error);
					return;
				}
				// Find next email to display before removing the active item
				const activeEl = document.querySelector('.email-item.active');
				let nextEl = null;
				if (activeEl) {
					// Try next sibling email, then previous
					let sib = activeEl.nextElementSibling;
					while (sib && !sib.classList.contains('email-item')) sib = sib.nextElementSibling;
					if (!sib) {
						sib = activeEl.previousElementSibling;
						while (sib && !sib.classList.contains('email-item')) sib = sib.previousElementSibling;
					}
					nextEl = sib;
					activeEl.remove();
				}
				if (nextEl) {
					nextEl.click();
				} else {
					clearViewPanel();
				}
			})
			.catch(err => console.error('Trash fetch error:', err));
		});
	}

	// Reply logic
	const btnReply = document.querySelector('.header-actions .fa-reply').parentElement;
	if (btnReply) {
		btnReply.addEventListener('click', () => {
			if (!currentEmail) return;
			
			const replyContainer = document.getElementById('reply-form-container');
			replyContainer.style.display = 'block';
			
			// Extract email from "Name <email@domain.com>" or just use the string
			let toEmail = currentEmail.from;
			const emailMatch = toEmail.match(/<([^>]+)>/);
			if (emailMatch) toEmail = emailMatch[1];
			
			document.getElementById('reply-to').value = toEmail;
			document.getElementById('reply-cc').value = '';
			
			let subject = currentEmail.subject;
			if (!subject.toLowerCase().startsWith('re:')) {
				subject = 'Re: ' + subject;
			}
			document.getElementById('reply-subject').value = subject;
			
			// Scroll to form
			replyContainer.scrollIntoView({ behavior: 'smooth' });
			
			// Initialize CKEditor if not already done
			if (typeof CKEDITOR !== 'undefined') {
				if (!CKEDITOR.instances.replybody) {
					CKEDITOR.replace('replybody');
				}
				
				// Set initial content (blockquote)
				const blockquote = `<br><br><blockquote style="border-left: 2px solid #ccc; margin-left: 10px; padding-left: 10px; color: #666;">
					<p>Le ${formatDateFull(currentEmail.date)}, ${currentEmail.from} a écrit :</p>
					${currentEmailBody}
				</blockquote><p><br></p>`;
				
				CKEDITOR.instances.replybody.setData(blockquote);
				// Focus editor
				setTimeout(() => { CKEDITOR.instances.replybody.focus(); }, 500);
			}
		});
	}

	const btnCancelReply = document.getElementById('btn-cancel-reply');
	if (btnCancelReply) {
		btnCancelReply.addEventListener('click', () => {
			document.getElementById('reply-form-container').style.display = 'none';
		});
	}

	let sendTimeout = null;
	let countdownInterval = null;

	const btnSendReply = document.getElementById('btn-send-reply');
	if (btnSendReply) {
		btnSendReply.addEventListener('click', () => {
			const delay = typeof inboxSendDelay !== 'undefined' ? inboxSendDelay : 10;
			
			let bodyContent = '';
			if (typeof CKEDITOR !== 'undefined' && CKEDITOR.instances.replybody) {
				bodyContent = CKEDITOR.instances.replybody.getData();
			} else {
				bodyContent = document.getElementById('replybody').value;
			}
			
			const formData = new URLSearchParams();
			formData.append('to', document.getElementById('reply-to').value);
			formData.append('cc', document.getElementById('reply-cc').value);
			formData.append('subject', document.getElementById('reply-subject').value);
			formData.append('body', bodyContent);
			formData.append('in_reply_to', currentEmail.uid);
			formData.append('account_id', currentAccountId);
			
			// Hide form immediately
			document.getElementById('reply-form-container').style.display = 'none';
			
			// Create or get banner
			let banner = document.getElementById('send-delay-banner');
			if (!banner) {
				banner = document.createElement('div');
				banner.id = 'send-delay-banner';
				banner.className = 'info';
				banner.style.padding = '15px';
				banner.style.marginBottom = '20px';
				banner.style.backgroundColor = '#fff3cd';
				banner.style.border = '1px solid #ffe69c';
				banner.style.color = '#664d03';
				banner.style.borderRadius = '4px';
				banner.style.display = 'flex';
				banner.style.justifyContent = 'space-between';
				banner.style.alignItems = 'center';
				
				const viewPanel = document.querySelector('.inbox-panel-content');
				viewPanel.insertBefore(banner, viewPanel.firstChild);
			}
			
			let timeLeft = delay;
			
			const updateBannerContent = () => {
				banner.innerHTML = `
					<span><i class="fa fa-clock-o"></i> L'email sera envoyé dans <strong>${timeLeft}</strong> secondes...</span>
					<button id="btn-abort-send" class="button" style="padding: 5px 10px; background: white; border: 1px solid #ccc; cursor: pointer;">Annuler l'envoi</button>
				`;
				
				document.getElementById('btn-abort-send').addEventListener('click', () => {
					clearTimeout(sendTimeout);
					clearInterval(countdownInterval);
					banner.style.display = 'none';
					document.getElementById('reply-form-container').style.display = 'block';
					if (typeof $ !== 'undefined' && $.jnotify) {
						$.jnotify("Envoi annulé.", "warning");
					} else {
						console.log("Envoi annulé.");
					}
				});
			};
			
			banner.style.display = 'flex';
			updateBannerContent();
			
			countdownInterval = setInterval(() => {
				timeLeft--;
				if (timeLeft > 0) {
					updateBannerContent();
				} else {
					clearInterval(countdownInterval);
				}
			}, 1000);
			
			// Actually send after delay
			sendTimeout = setTimeout(() => {
				banner.innerHTML = `<span><i class="fa fa-spinner fa-spin"></i> Expédition en cours...</span>`;
				
				fetch('../../custom/inbox/ajax/send_email.php', {
					method: 'POST',
					body: formData
				})
				.then(res => res.json())
				.then(data => {
					banner.style.display = 'none';
					if (data.error) {
						if (typeof $ !== 'undefined' && $.jnotify) {
							$.jnotify("Erreur lors de l'envoi : " + data.error, "error");
						} else {
							alert("Erreur lors de l'envoi : " + data.error);
						}
					} else {
						if (typeof $ !== 'undefined' && $.jnotify) {
							$.jnotify("Message envoyé avec succès !", "success");
						} else {
							alert("Message envoyé avec succès !");
						}
					}
				})
				.catch(err => {
					banner.style.display = 'none';
					document.getElementById('reply-form-container').style.display = 'block';
					if (typeof $ !== 'undefined' && $.jnotify) {
						$.jnotify("Erreur réseau.", "error");
					} else {
						alert("Erreur réseau.");
					}
					console.error(err);
				});
			}, delay * 1000);
		});
	}

	// ── Tags ─────────────────────────────────────────────────────────────────

	let allTags = [];

	const fetchAllTags = () => {
		fetch('../../custom/inbox/ajax/get_tags.php')
			.then(r => r.json())
			.then(data => { if (data.data) allTags = data.data; })
			.catch(() => {});
	};

	const renderViewTags = (messageTags) => {
		const container = document.getElementById('email-view-tags');
		if (!container) return;

		// Remove existing chips (keep picker wrapper and btn-add-tag)
		container.querySelectorAll('.tag-dynamic').forEach(el => el.remove());

		messageTags.forEach(t => {
			const chip = document.createElement('span');
			chip.className = 'tag tag-dynamic';
			chip.dataset.tagId = t.fk_tag;
			chip.style.background = t.tag_color || '#3b82f6';
			chip.innerHTML = `${escHtml(t.tag_label)}<button class="tag-remove" title="Retirer ce tag" aria-label="Retirer">&#x2715;</button>`;
			chip.querySelector('.tag-remove').addEventListener('click', (e) => {
				e.stopPropagation();
				removeMessageTag(t.fk_tag, chip);
			});
			// Insert before picker wrapper
			const pickerWrapper = document.getElementById('tag-picker-wrapper');
			container.insertBefore(chip, pickerWrapper);
		});
	};

	const loadMessageTags = (message_id) => {
		if (!message_id) { renderViewTags([]); return; }
		fetch('../../custom/inbox/ajax/get_message_tags.php?message_id=' + encodeURIComponent(message_id))
			.then(r => r.json())
			.then(data => renderViewTags(data.data || []))
			.catch(() => renderViewTags([]));
	};

	const escHtml = (s) => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

	const buildTagPicker = (message_id) => {
		const picker = document.getElementById('tag-picker');
		if (!picker) return;
		picker.innerHTML = '';

		if (!allTags.length) {
			picker.innerHTML = '<div class="tag-picker-empty">Aucun tag configuré.</div>';
			return;
		}

		// Collect already applied fk_tag ids from chips in DOM
		const applied = new Set(
			Array.from(document.querySelectorAll('#email-view-tags .tag-dynamic')).map(el => Number(el.dataset.tagId))
		);

		allTags.forEach(t => {
			const item = document.createElement('div');
			item.className = 'tag-picker-item' + (applied.has(t.rowid) ? ' active' : '');
			item.innerHTML = `<span class="tag-dot" style="background:${escHtml(t.color)};"></span>${escHtml(t.label)}`;
			item.addEventListener('click', () => {
				if (applied.has(t.rowid)) {
					// Remove
					const chip = document.querySelector(`#email-view-tags .tag-dynamic[data-tag-id="${t.rowid}"]`);
					if (chip) removeMessageTag(t.rowid, chip);
					item.classList.remove('active');
					applied.delete(t.rowid);
				} else {
					// Add
					addMessageTag(t, message_id, item);
					item.classList.add('active');
					applied.add(t.rowid);
				}
			});
			picker.appendChild(item);
		});
	};

	const addMessageTag = (tag, message_id, pickerItem) => {
		if (!currentEmail) return;
		const fd = new URLSearchParams();
		fd.append('fk_tag', tag.rowid);
		fd.append('message_id', message_id);
		fd.append('uid', currentEmail.uid);
		fd.append('folder', currentFolder);

		fetch('../../custom/inbox/ajax/add_message_tag.php', { method: 'POST', body: fd })
			.then(r => r.json())
			.then(data => {
				if (data.error) { console.error('add tag error:', data.error); return; }
				// Add chip to view
				const chip = document.createElement('span');
				chip.className = 'tag tag-dynamic';
				chip.dataset.tagId = tag.rowid;
				chip.style.background = tag.color || '#3b82f6';
				chip.innerHTML = `${escHtml(tag.label)}<button class="tag-remove" title="Retirer ce tag" aria-label="Retirer">&#x2715;</button>`;
				chip.querySelector('.tag-remove').addEventListener('click', (e) => {
					e.stopPropagation();
					removeMessageTag(tag.rowid, chip);
					if (pickerItem) pickerItem.classList.remove('active');
				});
				const pickerWrapper = document.getElementById('tag-picker-wrapper');
				document.getElementById('email-view-tags').insertBefore(chip, pickerWrapper);
			})
			.catch(err => console.error(err));
	};

	const removeMessageTag = (fk_tag, chipEl) => {
		if (!currentEmail) return;
		const fd = new URLSearchParams();
		fd.append('fk_tag', fk_tag);
		fd.append('message_id', currentEmail.message_id);
		fd.append('uid', currentEmail.uid);
		fd.append('folder', currentFolder);

		chipEl.style.opacity = '0.4';
		fetch('../../custom/inbox/ajax/remove_message_tag.php', { method: 'POST', body: fd })
			.then(r => r.json())
			.then(data => {
				if (data.error) { chipEl.style.opacity = '1'; console.error('remove tag error:', data.error); return; }
				chipEl.remove();
			})
			.catch(err => { chipEl.style.opacity = '1'; console.error(err); });
	};

	// Tag picker toggle
	const btnAddTag = document.getElementById('btn-add-tag');
	const tagPickerWrapper = document.getElementById('tag-picker-wrapper');

	if (btnAddTag && tagPickerWrapper) {
		btnAddTag.addEventListener('click', (e) => {
			e.stopPropagation();
			const open = tagPickerWrapper.style.display !== 'none';
			if (open) {
				tagPickerWrapper.style.display = 'none';
			} else {
				buildTagPicker(currentEmail ? currentEmail.message_id : null);
				// Position fixed relative to the button (escapes overflow:auto clipping)
				const rect = btnAddTag.getBoundingClientRect();
				tagPickerWrapper.style.top  = (rect.bottom + 4) + 'px';
				tagPickerWrapper.style.left = rect.left + 'px';
				tagPickerWrapper.style.display = 'block';
			}
		});

		document.addEventListener('click', (e) => {
			if (!tagPickerWrapper.contains(e.target) && e.target !== btnAddTag) {
				tagPickerWrapper.style.display = 'none';
			}
		});
	}

	fetchAllTags();

	// ── Comments ────────────────────────────────────────────────────────────

	const commentsList    = document.querySelector('.comments-list');
	const commentBadge    = document.querySelector('.comments-section .badge');
	const commentTextarea = document.querySelector('.comment-input-area textarea');
	const commentBtn      = document.querySelector('.comment-input-area .btn-primary');

	const buildCommentEl = (c) => {
		const div = document.createElement('div');
		div.className = 'comment-item';
		div.dataset.rowid = c.rowid;
		div.innerHTML = `
			<div class="comment-avatar">${c.initials}</div>
			<div class="comment-content">
				<div class="comment-meta">
					<span class="comment-author">${c.author}</span>
					<span class="comment-date">${c.date}</span>
					${c.is_mine ? '<button class="btn-delete-comment" style="margin-left:8px;background:none;border:none;cursor:pointer;color:#94a3b8;font-size:0.8em;" title="Supprimer"><i class="fa fa-times"></i></button>' : ''}
				</div>
				<div class="comment-text">${c.comment.replace(/\n/g, '<br>')}</div>
			</div>
		`;
		if (c.is_mine) {
			div.querySelector('.btn-delete-comment').addEventListener('click', () => {
				const fd = new URLSearchParams();
				fd.append('rowid', c.rowid);
				fetch('../../custom/inbox/ajax/delete_comment.php', { method: 'POST', body: fd })
					.then(r => r.json())
					.then(data => {
						if (data.error) { console.error(data.error); return; }
						div.remove();
						const count = commentsList.querySelectorAll('.comment-item').length;
						if (commentBadge) commentBadge.textContent = count;
					})
					.catch(err => console.error(err));
			});
		}
		return div;
	};

	const loadComments = (uid, folder, message_id) => {
		if (!commentsList) return;
		commentsList.innerHTML = '<div style="color:#94a3b8;font-size:0.85em;padding:8px 0;">Chargement...</div>';
		const url = '../../custom/inbox/ajax/get_comments.php?uid=' + encodeURIComponent(uid)
			+ '&folder=' + encodeURIComponent(folder)
			+ (message_id ? '&message_id=' + encodeURIComponent(message_id) : '');
		fetch(url)
			.then(r => r.json())
			.then(data => {
				commentsList.innerHTML = '';
				if (data.error) { commentsList.innerHTML = '<div style="color:red;">' + data.error + '</div>'; return; }
				(data.data || []).forEach(c => commentsList.appendChild(buildCommentEl(c)));
				if (commentBadge) commentBadge.textContent = (data.data || []).length;
			})
			.catch(err => { commentsList.innerHTML = ''; console.error(err); });
	};

	if (commentBtn && commentTextarea) {
		commentBtn.addEventListener('click', () => {
			const text = commentTextarea.value.trim();
			if (!text || !currentEmail) return;

			const fd = new URLSearchParams();
			fd.append('uid',    currentEmail.uid);
			fd.append('folder', currentFolder);
			fd.append('comment', text);
			if (currentEmail.message_id) fd.append('message_id', currentEmail.message_id);

			commentBtn.disabled = true;
			fetch('../../custom/inbox/ajax/add_comment.php', { method: 'POST', body: fd })
				.then(r => r.json())
				.then(data => {
					commentBtn.disabled = false;
					if (data.error) { console.error(data.error); return; }
					commentsList.appendChild(buildCommentEl(data.comment));
					commentsList.scrollTop = commentsList.scrollHeight;
					commentTextarea.value = '';
					const count = commentsList.querySelectorAll('.comment-item').length;
					if (commentBadge) commentBadge.textContent = count;
				})
				.catch(err => { commentBtn.disabled = false; console.error(err); });
		});

		// Submit on Ctrl+Enter
		commentTextarea.addEventListener('keydown', (e) => {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) commentBtn.click();
		});
	}

	// Remote images banner: restore src attributes when user chooses to display images
	const btnShowImages = document.getElementById('btn-show-images');
	if (btnShowImages) {
		btnShowImages.addEventListener('click', () => {
			if (currentIframe && currentIframe.contentDocument) {
				currentIframe.contentDocument.querySelectorAll('[data-original-src]').forEach(el => {
					el.src = el.getAttribute('data-original-src');
					el.removeAttribute('data-original-src');
				});
				// Resize iframe after images load
				setTimeout(() => {
					try {
						const h = currentIframe.contentDocument.documentElement.scrollHeight;
						currentIframe.style.height = Math.max(200, h) + 'px';
					} catch (e) {}
				}, 500);
			}
			document.getElementById('remote-images-banner').style.display = 'none';
		});
	}
});
