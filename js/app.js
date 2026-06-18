/**
 * app.js - Frontend Logic for Inbox Module
 * Implements Context JS principles for real-time interactions
 */

document.addEventListener('DOMContentLoaded', () => {
	console.log("Inbox module initialized");

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

	// Pagination state
	let emailPage = 0;
	const EMAIL_PAGE_SIZE = 50;
	let emailsLoading = false;
	let emailsHasMore = false;

	// Fetch folders
	const fetchFolders = () => {
		fetch('../../custom/inbox/ajax/get_folders.php')
			.then(response => response.json())
			.then(data => {
				const ul = document.getElementById('dynamic-folder-list');
				ul.innerHTML = '';
				
				if (data.error) {
					ul.innerHTML = '<li><i class="fa fa-exclamation-triangle"></i> ' + data.error + '</li>';
					return;
				}
				
				data.data.forEach(f => {
					const li = document.createElement('li');
					
					let icon = 'fa-folder';
					if (f.type == 'inbox') icon = 'fa-inbox';
					else if (f.type == 'sent') icon = 'fa-paper-plane';
					else if (f.type == 'drafts') icon = 'fa-file';
					else if (f.type == 'trash') icon = 'fa-trash';
					else if (f.type == 'archive') icon = 'fa-archive';
					
					li.innerHTML = '<i class="fa ' + icon + '"></i> ' + f.name;
					li.dataset.id = f.id;
					
					if (f.id == currentFolder || (currentFolder == 'INBOX' && f.type == 'inbox')) {
						li.className = 'active';
						currentFolder = f.id; // Normalize default inbox name
					}
					
					li.addEventListener('click', () => {
						document.querySelectorAll('#dynamic-folder-list li').forEach(el => el.classList.remove('active'));
						li.classList.add('active');
						currentFolder = f.id;

						// Clear view (elements may not exist depending on current state)
						const emptyState = document.getElementById('email-empty-state');
						const viewContent = document.getElementById('email-view-content');
						if (emptyState) emptyState.style.display = 'block';
						if (viewContent) viewContent.style.display = 'none';
						document.getElementById('reply-form-container').style.display = 'none';

						fetchEmails();
					});
					
					ul.appendChild(li);
				});
				
				// Initial fetch
				fetchEmails();
			})
			.catch(err => {
				console.error("Error fetching folders:", err);
				document.getElementById('dynamic-folder-list').innerHTML = '<li><i class="fa fa-exclamation-triangle"></i> Erreur réseau</li>';
			});
	};

	// Build a single email list item element
	const buildEmailEl = (email) => {
		const el = document.createElement('div');
		el.className = 'email-item ' + (email.seen ? '' : 'unread');
		el.innerHTML = `
			<div class="email-item-header">
				<span class="email-sender">${email.from}</span>
				<span class="email-date">${email.date}</span>
			</div>
			<div class="email-subject">${email.subject}</div>
		`;
		el.addEventListener('click', (e) => {
			document.querySelectorAll('.email-item').forEach(em => em.classList.remove('active'));
			e.currentTarget.classList.add('active');
			e.currentTarget.classList.remove('unread');

			currentEmail = email;

			document.getElementById('panel-view').style.display = 'flex';
			document.getElementById('reply-form-container').style.display = 'none';

			document.querySelector('.email-view-subject').innerText = email.subject;
			document.querySelector('.sender-name').innerHTML = `${email.from} <a href="#" class="link-erp"><i class="fa fa-user"></i> Contact</a>`;
			document.querySelector('.email-view-date').innerText = email.date;

			const bodyContainer = document.querySelector('.email-view-body');
			bodyContainer.innerHTML = '<div style="text-align:center; padding: 40px; color: #888;"><i class="fa fa-spinner fa-spin fa-2x"></i><br>Chargement...</div>';

			fetch('../../custom/inbox/ajax/get_email_body.php?msgno=' + email.msgno + '&folder=' + encodeURIComponent(currentFolder))
				.then(res => {
					if (!res.ok) throw new Error("HTTP error " + res.status);
					return res.json();
				})
				.then(bodyData => {
					if (bodyData.error) {
						bodyContainer.innerHTML = '<div style="color:red; padding:20px;">Erreur lors du chargement du corps: ' + bodyData.error + '</div>';
						currentEmailBody = '';
					} else {
						bodyContainer.innerHTML = bodyData.body;
						currentEmailBody = bodyData.body;
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

	// Fetch emails from IMAP via AJAX — reset=true replaces the list, false appends next page
	const fetchEmails = (reset = true) => {
		if (emailsLoading) return;
		emailsLoading = true;

		const container = document.getElementById('email-list-container');
		const sentinel = document.getElementById('email-list-sentinel');

		if (reset) {
			emailPage = 0;
			emailsHasMore = false;
			clearEmailList();
			const spinner = document.createElement('div');
			spinner.style.cssText = 'padding: 20px; text-align: center; color: #64748b;';
			spinner.innerHTML = '<i class="fa fa-spinner fa-spin fa-2x"></i><br>Chargement des messages...';
			container.insertBefore(spinner, sentinel);
		} else {
			// Show a subtle loading indicator above the sentinel
			const loader = document.createElement('div');
			loader.id = 'email-page-loader';
			loader.style.cssText = 'padding: 12px; text-align: center; color: #64748b; font-size: 0.9em;';
			loader.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Chargement...';
			container.insertBefore(loader, sentinel);
		}

		const url = '../../custom/inbox/ajax/get_emails.php?folder=' + encodeURIComponent(currentFolder)
			+ '&offset=' + (emailPage * EMAIL_PAGE_SIZE);

		fetch(url)
			.then(response => response.json())
			.then(data => {
				emailsLoading = false;
				document.getElementById('email-page-loader')?.remove();

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
			})
			.catch(err => {
				emailsLoading = false;
				document.getElementById('email-page-loader')?.remove();
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

	// Add sentinel for infinite scroll at the bottom of the email list
	const emailSentinel = document.createElement('div');
	emailSentinel.id = 'email-list-sentinel';
	emailSentinel.style.height = '10px';
	document.getElementById('email-list-container').appendChild(emailSentinel);

	const scrollObserver = new IntersectionObserver((entries) => {
		if (entries[0].isIntersecting && !emailsLoading && emailsHasMore) {
			fetchEmails(false);
		}
	}, { threshold: 0.1 });
	scrollObserver.observe(emailSentinel);

	// Trigger fetch on load
	fetchFolders();

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
			}
		}, refreshInterval * 1000);
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
					<p>Le ${currentEmail.date}, ${currentEmail.from} a écrit :</p>
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
			formData.append('in_reply_to', currentEmail.msgno);
			
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

	// 3. Comment submission
	const commentBtn = document.querySelector('.comment-input-area .btn-primary');
	const commentTextarea = document.querySelector('.comment-input-area textarea');
	
	if (commentBtn && commentTextarea) {
		commentBtn.addEventListener('click', () => {
			const text = commentTextarea.value.trim();
			if (text) {
				// Fake adding comment
				const commentsList = document.querySelector('.comments-list');
				const newComment = document.createElement('div');
				newComment.className = 'comment-item';
				newComment.innerHTML = `
					<div class="comment-avatar">Me</div>
					<div class="comment-content">
						<div class="comment-meta"><span class="comment-author">Current User</span> <span class="comment-date">Maintenant</span></div>
						<div class="comment-text">"${text}"</div>
					</div>
				`;
				commentsList.appendChild(newComment);
				commentTextarea.value = '';
			}
		});
	}
});
