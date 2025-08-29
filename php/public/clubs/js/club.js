(function () {
    // ---------- small helpers ----------
    const qs  = (s, r = document) => r.querySelector(s);
    const qsa = (s, r = document) => Array.from(r.querySelectorAll(s));
    const escapeHtml = (s) =>
        (s || "").replace(/[&<>"']/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m]));

    const showMsg = (text, cls = "success") => {
        const box = qs("#clubMsg");
        if (!box) return;
        box.innerHTML = `<div class="alert alert-${cls}">${text}</div>`;
        setTimeout(() => (box.innerHTML = ""), cls === "success" ? 2500 : 5000);
    };

    const postForm = (url, params) =>
        fetch(url, {
            method: "POST",
            headers: { "Content-Type": "application/x-www-form-urlencoded" },
            body: new URLSearchParams(params),
        }).then((r) => r.json());

    // These endpoints resolve relative to the current page (not this JS file)
    const postClubField = (clubUuid, field, value) =>
        postForm("update_club_field.php", { club_uuid: clubUuid, field_name: field, field_value: value });

    const postLocationField = (locationUuid, field, value) =>
        postForm("update_location_field.php", { location_uuid: locationUuid, field_name: field, field_value: value });

    // ==========================================================
    // CLUB DETAILS PAGE INITIALIZER
    // ==========================================================
    function initClubDetailsPage() {
        const meta = qs("#clubMeta");
        if (!meta) return; // not on club-details page

        let clubUuid = meta.dataset.clubUuid || "";
        // ---------- Delete Club ----------
        const deleteBtn = qs("#deleteClubBtn");
        if (deleteBtn) {
            deleteBtn.addEventListener("click", async () => {
                const name = (qs("#clubNameHeading")?.textContent || "this club").trim();
                if (!confirm(`Delete "${name}"?`)) return;
                if (!confirm(`This cannot be undone. If the club has past matches it will be archived instead.\n\nContinue?`)) return;

                try {
                    const res = await fetch("delete_club.php", {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: new URLSearchParams({ club_uuid: clubUuid })
                    });
                    const data = await res.json();

                    if (data.status !== "success") throw new Error(data.message || "Delete failed");

                    if (data.club_deleted) {
                        alert(`Club deleted.\nFuture matches removed: ${data.future_matches_deleted}\nFuture travel logs removed: ${data.future_travel_logs_deleted}`);
                        window.location.href = "../clubs.php";
                    } else if (data.club_archived) {
                        alert(
                            `Club archived (historical matches exist).\n` +
                            `Future matches removed: ${data.future_matches_deleted}\n` +
                            `Future travel logs removed: ${data.future_travel_logs_deleted}`
                        );
                        // Reflect archived state in UI (status chip, etc.)
                        const statusText = qs("#statusText");
                        if (statusText) statusText.innerHTML = '<span class="badge bg-secondary">Inactive</span>';
                    } else {
                        alert("No changes were made.");
                    }
                } catch (e) {
                    alert(e.message || "Server error.");
                }
            });
        }
        let locationUuid = meta.dataset.locationUuid || "";

        // ---------- Address edit / display ----------
        const addressRowDisplay = qs("#addressRowDisplay");
        const addressRowEdit = qs("#addressRowEdit");
        const addressText = qs("#addressText");
        const addressInput = qs("#addressInput");
        const editAddrBtn = qs("#editAddressBtn");
        const saveAddrBtn = qs("#saveAddressBtn");
        const cancelAddrBtn = qs("#cancelAddressBtn");
        const copyAddrBtn = qs("#copyAddressBtn");
        const openMapsBtn = qs("#openMapsBtn");

        if (copyAddrBtn && addressText && addressText.textContent.trim()) {
            copyAddrBtn.addEventListener("click", async () => {
                try {
                    await navigator.clipboard.writeText(addressText.textContent.trim());
                    copyAddrBtn.innerHTML = '<i class="bi bi-clipboard-check"></i>';
                    setTimeout(() => (copyAddrBtn.innerHTML = '<i class="bi bi-clipboard"></i>'), 1500);
                } catch {}
            });
        }

        const toggleAddrEdit = (editing) => {
            if (!addressRowDisplay || !addressRowEdit) return;
            addressRowDisplay.style.display = editing ? "none" : "";
            addressRowEdit.style.display = editing ? "" : "none";
            if (editing) addressInput?.focus();
        };

        editAddrBtn?.addEventListener("click", () => toggleAddrEdit(true));
        cancelAddrBtn?.addEventListener("click", () => {
            if (addressInput) addressInput.value = addressText?.textContent.trim() || "";
            toggleAddrEdit(false);
        });
        saveAddrBtn?.addEventListener("click", async () => {
            if (!locationUuid) return;
            const val = (addressInput?.value || "").trim();
            try {
                const data = await postLocationField(locationUuid, "address_text", val);
                if (data.status !== "success") throw new Error(data.message || "Update failed");
                if (addressText) addressText.textContent = val || "—";
                if (openMapsBtn) {
                    if (val) {
                        openMapsBtn.href = "https://maps.google.com/?q=" + encodeURIComponent(val);
                        openMapsBtn.style.display = "";
                    } else {
                        openMapsBtn.style.display = "none";
                    }
                }
                if (copyAddrBtn) copyAddrBtn.style.display = val ? "" : "none";
                toggleAddrEdit(false);
                showMsg("Address saved.", "success");
            } catch (e) {
                showMsg(e.message || "Update failed.", "danger");
            }
        });

        // ---------- Primary contact edit ----------
        const editContactBtn = qs("#editContactBtn");
        const contactDisplay = qs("#contactDisplay");
        const contactEdit = qs("#contactEdit");
        const contactNameText = qs("#contactNameText");
        const contactEmailText = qs("#contactEmailText");
        const contactPhoneText = qs("#contactPhoneText");
        const contactNameInput = qs("#contactNameInput");
        const contactEmailInput = qs("#contactEmailInput");
        const contactPhoneInput = qs("#contactPhoneInput");
        const saveContactBtn = qs("#saveContactBtn");
        const cancelContactBtn = qs("#cancelContactBtn");

        const toggleContactEdit = (editing) => {
            if (!contactDisplay || !contactEdit) return;
            contactDisplay.style.display = editing ? "none" : "";
            contactEdit.style.display = editing ? "" : "none";
            if (editing) contactNameInput?.focus();
        };

        editContactBtn?.addEventListener("click", () => toggleContactEdit(true));
        cancelContactBtn?.addEventListener("click", () => {
            contactNameInput.value = (contactNameText?.textContent.trim() || "").replace(/^—$/, "");
            const emailA = contactEmailText?.querySelector("a");
            contactEmailInput.value = emailA ? emailA.textContent.trim() : "";
            const phoneA = contactPhoneText?.querySelector("a");
            contactPhoneInput.value = phoneA ? phoneA.textContent.trim() : "";
            toggleContactEdit(false);
        });

        saveContactBtn?.addEventListener("click", async () => {
            const name = (contactNameInput?.value || "").trim();
            const email = (contactEmailInput?.value || "").trim();
            const phone = (contactPhoneInput?.value || "").trim();
            try {
                let r1 = await postClubField(clubUuid, "primary_contact_name", name);
                if (r1.status !== "success") throw new Error(r1.message || "Update failed");
                let r2 = await postClubField(clubUuid, "primary_contact_email", email);
                if (r2.status !== "success") throw new Error(r2.message || "Update failed");
                let r3 = await postClubField(clubUuid, "primary_contact_phone", phone);
                if (r3.status !== "success") throw new Error(r3.message || "Update failed");

                if (contactNameText) contactNameText.textContent = name || "—";
                if (contactEmailText)
                    contactEmailText.innerHTML = email
                        ? `<a href="mailto:${escapeHtml(email)}" class="truncate"><i class="bi bi-envelope me-1"></i>${escapeHtml(email)}</a>`
                        : "—";
                if (contactPhoneText)
                    contactPhoneText.innerHTML = phone
                        ? `<a href="tel:${escapeHtml(phone)}"><i class="bi bi-telephone me-1"></i>${escapeHtml(phone)}</a>`
                        : "—";

                toggleContactEdit(false);
                showMsg("Primary contact saved.", "success");
            } catch (e) {
                showMsg(e.message || "Failed to save contact.", "danger");
            }
        });

        // ---------- Notes edit ----------
        const editNotesBtn = qs("#editNotesBtn");
        const notesDisplay = qs("#notesDisplay");
        const notesEdit = qs("#notesEdit");
        const notesTextarea = qs("#notesTextarea");
        const saveNotesBtn = qs("#saveNotesBtn");
        const cancelNotesBtn = qs("#cancelNotesBtn");

        const toggleNotesEdit = (editing) => {
            if (!notesDisplay || !notesEdit) return;
            notesDisplay.style.display = editing ? "none" : "";
            notesEdit.style.display = editing ? "" : "none";
            if (editing) notesTextarea?.focus();
        };

        editNotesBtn?.addEventListener("click", () => toggleNotesEdit(true));
        cancelNotesBtn?.addEventListener("click", () => {
            const pre = qs("#notesPre");
            const empty = qs("#notesEmpty");
            notesTextarea.value = pre ? pre.textContent : empty ? "" : "";
            toggleNotesEdit(false);
        });

        saveNotesBtn?.addEventListener("click", async () => {
            const val = (notesTextarea?.value || "").trim();
            try {
                const r = await postClubField(clubUuid, "notes", val);
                if (r.status !== "success") throw new Error(r.message || "Update failed");

                if (notesDisplay) {
                    notesDisplay.innerHTML = "";
                    if (val) {
                        const pre = document.createElement("pre");
                        pre.id = "notesPre";
                        pre.className = "mb-0";
                        pre.style.whiteSpace = "pre-wrap";
                        pre.textContent = val;
                        notesDisplay.appendChild(pre);
                    } else {
                        const span = document.createElement("span");
                        span.id = "notesEmpty";
                        span.className = "text-muted";
                        span.textContent = "No notes yet.";
                        notesDisplay.appendChild(span);
                    }
                }

                toggleNotesEdit(false);
                showMsg("Notes saved.", "success");
            } catch (e) {
                showMsg(e.message || "Failed to save notes.", "danger");
            }
        });

        // ---------- Teams (add/edit/delete) ----------
        const isSuper = !!qs("#teamModal");
        if (isSuper) {
            const teamModalEl = qs("#teamModal");
            const teamModal = teamModalEl ? new bootstrap.Modal(teamModalEl) : null;
            const teamModalMsg = qs("#teamModalMsg");
            const teamModalLabel = qs("#teamModalLabel");
            const teamNameInput = qs("#teamNameInput");
            const districtSelect = qs("#districtSelect");
            const divisionSelect = qs("#divisionSelect");
            const saveTeamBtn = qs("#saveTeamBtn");
            const addBtn = qs("#addTeamBtn");
            const tbody = qs("#teamsTableBody");
            const noTeamsRow = qs("#noTeamsRow");

            let modalMode = "add"; // 'add' | 'edit'
            let editingTeamUuid = null;

            const showModalMsg = (html, cls = "danger") => {
                if (!teamModalMsg) return;
                teamModalMsg.innerHTML = `<div class="alert alert-${cls} py-2 mb-2">${html}</div>`;
            };
            const clearValidation = () => {
                [teamNameInput, districtSelect, divisionSelect].forEach((el) => el?.classList.remove("is-invalid"));
            };
            const resetModal = () => {
                teamModalMsg.innerHTML = "";
                teamNameInput.value = "";
                districtSelect.value = "";
                divisionSelect.value = "";
                clearValidation();
            };
            const validateTeamForm = () => {
                clearValidation();
                let ok = true;
                if (!teamNameInput.value.trim()) {
                    teamNameInput.classList.add("is-invalid");
                    ok = false;
                }
                if (!districtSelect.value) {
                    districtSelect.classList.add("is-invalid");
                    ok = false;
                }
                if (!divisionSelect.value) {
                    divisionSelect.classList.add("is-invalid");
                    ok = false;
                }
                return ok;
            };

            addBtn?.addEventListener("click", () => {
                modalMode = "add";
                editingTeamUuid = null;
                resetModal();
                teamModalLabel.textContent = "Add New Team";
                teamModal?.show();
                setTimeout(() => teamNameInput?.focus(), 150);
            });

            tbody?.addEventListener("click", (e) => {
                const btn = e.target.closest("button");
                if (!btn) return;
                const tr = btn.closest("tr");
                if (!tr) return;

                // EDIT
                if (btn.classList.contains("edit-team")) {
                    modalMode = "edit";
                    editingTeamUuid = tr.dataset.teamUuid;
                    resetModal();
                    teamModalLabel.textContent = "Edit Team";
                    teamNameInput.value = tr.dataset.teamName || "";
                    divisionSelect.value = tr.dataset.division || "";
                    districtSelect.value = tr.dataset.districtId && tr.dataset.districtId !== "0" ? tr.dataset.districtId : "";
                    teamModal?.show();
                    setTimeout(() => teamNameInput?.focus(), 150);
                }

                if (btn.classList.contains("delete-team")) {
                    const teamName = tr.dataset.teamName || "this team";
                    if (!confirm(`Are you sure you want to delete "${teamName}"?`)) return;
                    if (!confirm(`This will permanently delete "${teamName}". Are you REALLY sure?`)) return;

                    postForm("delete_team.php", { team_uuid: tr.dataset.teamUuid })
                        .then((data) => {
                            if (data.status !== "success") throw new Error(data.message || "Failed to delete");

                            if (data.team_deleted) {
                                tr.remove();
                                if (!tbody.querySelector("tr")) {
                                    const r = document.createElement("tr");
                                    r.id = "noTeamsRow";
                                    r.innerHTML = `<td colspan="3"><span class="text-muted">No teams found for this club.</span></td>`;
                                    tbody.appendChild(r);
                                }
                                alert(
                                    `Team deleted.\nFuture matches removed: ${data.future_matches_deleted}\nTravel logs removed: ${data.future_travel_logs_deleted}`
                                );
                            } else if (data.team_archived) {
                                tr.dataset.active = "0";
                                const nameCell = tr.children[0];
                                if (!nameCell.querySelector(".badge")) {
                                    const badge = document.createElement("span");
                                    badge.className = "badge bg-secondary ms-2";
                                    badge.textContent = "Inactive";
                                    nameCell.appendChild(badge);
                                }
                                alert(
                                    `Team archived (cannot delete because ${data.past_matches_retained} past match(es) reference it).\n` +
                                    `Future matches removed: ${data.future_matches_deleted}\n` +
                                    `Future travel logs removed: ${data.future_travel_logs_deleted}`
                                );
                            } else {
                                alert("No changes were made.");
                            }
                        })
                        .catch((err) => alert(err.message || "Delete failed."));
                }
            });

            // Save (ADD or EDIT)
            saveTeamBtn?.addEventListener("click", () => {
                if (!validateTeamForm()) {
                    showModalMsg("Please complete all required fields.");
                    return;
                }
                const name = teamNameInput.value.trim();
                const districtId = districtSelect.value;
                const division = divisionSelect.value;

                const url = modalMode === "add" ? "add_team.php" : "update_team.php";
                const payload =
                    modalMode === "add"
                        ? { club_uuid: clubUuid, team_name: name, district_id: districtId, division }
                        : { team_uuid: editingTeamUuid, team_name: name, district_id: districtId, division };

                postForm(url, payload)
                    .then((data) => {
                        if (data.status !== "success") throw new Error(data.message || "Save failed");

                        if (modalMode === "add") {
                            if (noTeamsRow) noTeamsRow.remove();
                            const districtOpt  = districtSelect.options[districtSelect.selectedIndex];
                            const districtName = districtOpt ? districtOpt.textContent : '—';

                            const tr = document.createElement('tr');
                            tr.dataset.teamUuid   = data.team.uuid;
                            tr.dataset.teamName   = data.team.team_name;
                            tr.dataset.division   = data.team.division || '';
                            tr.dataset.districtId = data.team.district_id || 0;

                            tr.innerHTML = `
                                  <td>${escapeHtml(data.team.team_name)}</td>
                                  <td>${escapeHtml(districtName)}</td>
                                  <td>${escapeHtml(data.team.division || '—')}</td>
                                  <td class="text-nowrap">
                                    <button class="btn btn-sm btn-outline-secondary me-2 edit-team"><i class="bi bi-pencil-square me-1"></i>Edit</button>
                                    <button class="btn btn-sm btn-outline-danger delete-team"><i class="bi bi-trash me-1"></i>Delete</button>
                                  </td>
                            `;
                            tbody.appendChild(tr);
                        } else {
                            const tr = tbody.querySelector(`tr[data-team-uuid="${CSS.escape(editingTeamUuid)}"]`);
                            if (tr) {
                                const districtOpt = districtSelect.options[districtSelect.selectedIndex];
                                const districtName = districtOpt ? districtOpt.textContent : '—';

                                tr.dataset.teamName = name;
                                tr.dataset.division = division || '';
                                tr.dataset.districtId = districtId || 0;

                                tr.children[0].textContent = name;                   // Team
                                tr.children[1].textContent = districtName || '—';    // District
                                tr.children[2].textContent = division || '—';        // Division
                            }
                        }
                        teamModal?.hide();
                    })
                    .catch((err) => showModalMsg(err.message || "Unexpected error."));
            });
        }

        // ---------- Manage Location (single flow for edit/use existing/create) ----------
        const manageBtn = qs("#manageLocationBtn");
        const mlModalEl = qs("#manageLocationModal");
        const mlModal = mlModalEl ? new bootstrap.Modal(mlModalEl) : null;

        const fieldNameText = qs("#fieldNameText");
        const copyAddressBtn2 = qs("#copyAddressBtn");

        // Edit Current tab
        const ml_fieldNameInput = qs("#ml_fieldNameInput");
        const ml_addressInput = qs("#ml_addressInput");
        const ml_saveEditBtn = qs("#ml_saveEditBtn");
        const editCurrentMsg = qs("#editCurrentMsg");

        // Use Existing tab
        const ml_searchInput = qs("#ml_searchInput");
        const ml_resultsBody = qs("#ml_resultsBody");
        const ml_useSelectedBtn = qs("#ml_useSelectedBtn");
        const useExistingMsg = qs("#useExistingMsg");
        let selectedLocationUuid = null;

        // Create New tab
        const ml_newFieldName = qs("#ml_newFieldName");
        const ml_newAddress = qs("#ml_newAddress");
        const ml_createLinkBtn = qs("#ml_createLinkBtn");
        const createNewMsg = qs("#createNewMsg");

        function setAlert(el, text, cls = "danger") {
            if (!el) return;
            el.innerHTML = text ? `<div class="alert alert-${cls} py-2 mb-2">${text}</div>` : "";
        }

        manageBtn?.addEventListener("click", () => {
            setAlert(editCurrentMsg, "");
            setAlert(useExistingMsg, "");
            setAlert(createNewMsg, "");
            selectedLocationUuid = null;
            if (ml_useSelectedBtn) ml_useSelectedBtn.disabled = true;
            if (ml_resultsBody) ml_resultsBody.innerHTML = "";
            mlModal?.show();
            // Preload list
            fetchLocations("");
        });

        // Save current edits
        ml_saveEditBtn?.addEventListener("click", async () => {
            if (!locationUuid) {
                setAlert(editCurrentMsg, "No current location to edit. Use another tab.", "warning");
                return;
            }
            const name = (ml_fieldNameInput?.value || "").trim();
            const addr = (ml_addressInput?.value || "").trim();
            if (!name) {
                setAlert(editCurrentMsg, "Field name is required.");
                return;
            }
            try {
                let r1 = await postLocationField(locationUuid, "name", name);
                if (r1.status !== "success") throw new Error(r1.message || "Update failed");
                let r2 = await postLocationField(locationUuid, "address_text", addr);
                if (r2.status !== "success") throw new Error(r2.message || "Update failed");

                if (fieldNameText) fieldNameText.textContent = name || "—";
                if (addressText) addressText.textContent = addr || "—";
                if (copyAddressBtn2) copyAddressBtn2.style.display = addr ? "" : "none";
                if (openMapsBtn) {
                    if (addr) {
                        openMapsBtn.href = "https://maps.google.com/?q=" + encodeURIComponent(addr);
                        openMapsBtn.style.display = "";
                    } else {
                        openMapsBtn.style.display = "none";
                    }
                }

                setAlert(editCurrentMsg, "Location updated.", "success");
                showMsg("Location updated.", "success");
            } catch (e) {
                setAlert(editCurrentMsg, e.message || "Update failed.");
            }
        });

        // Search / use existing
        let searchTimer = null;
        ml_searchInput?.addEventListener("input", () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => fetchLocations(ml_searchInput.value.trim()), 250);
        });

        function fetchLocations(q) {
            // club-details.php lives in /clubs/, ajax endpoint lives in /ajax/
            fetch("../ajax/search_locations.php?q=" + encodeURIComponent(q || ""), { method: "GET" })
                .then((r) => r.json())
                .then((data) => {
                    if (data.status !== "success") throw new Error(data.message || "Search failed");
                    renderLocationRows(data.results || []);
                })
                .catch((err) => setAlert(useExistingMsg, err.message || "Search error."));
        }

        function renderLocationRows(rows) {
            if (!ml_resultsBody) return;
            ml_resultsBody.innerHTML = "";
            if (!rows.length) {
                ml_resultsBody.innerHTML = '<tr><td colspan="4" class="text-muted">No locations found.</td></tr>';
                return;
            }
            rows.forEach((loc) => {
                const tr = document.createElement("tr");
                tr.innerHTML = `
          <td>${escapeHtml(loc.name || "—")}</td>
          <td>${escapeHtml(loc.address_text || "")}</td>
          <td>${loc.clubs_count ?? 0} club(s)</td>
          <td><button class="btn btn-sm ${selectedLocationUuid === loc.uuid ? "btn-primary" : "btn-outline-primary"} select-loc" data-uuid="${
                    loc.uuid
                }">Select</button></td>
        `;
                ml_resultsBody.appendChild(tr);
            });
        }

        ml_resultsBody?.addEventListener("click", (e) => {
            const btn = e.target.closest(".select-loc");
            if (!btn) return;
            selectedLocationUuid = btn.getAttribute("data-uuid");
            ml_resultsBody.querySelectorAll(".select-loc").forEach((b) => b.classList.replace("btn-primary", "btn-outline-primary"));
            btn.classList.replace("btn-outline-primary", "btn-primary");
            if (ml_useSelectedBtn) ml_useSelectedBtn.disabled = !selectedLocationUuid;
        });

        ml_useSelectedBtn?.addEventListener("click", () => {
            if (!selectedLocationUuid) return;
            if (!confirm("Link this club to the selected location? This location may be shared by multiple clubs.")) return;

            postForm("link_existing_location.php", { club_uuid: clubUuid, location_uuid: selectedLocationUuid })
                .then((data) => {
                    if (data.status !== "success") throw new Error(data.message || "Link failed");

                    locationUuid = data.location.uuid;
                    const name = data.location.name || "—";
                    const addr = data.location.address_text || "";

                    if (fieldNameText) fieldNameText.textContent = name;
                    if (addressText) addressText.textContent = addr || "—";
                    if (copyAddressBtn2) copyAddressBtn2.style.display = addr ? "" : "none";
                    if (openMapsBtn) {
                        if (addr) {
                            openMapsBtn.href = "https://maps.google.com/?q=" + encodeURIComponent(addr);
                            openMapsBtn.style.display = "";
                        } else {
                            openMapsBtn.style.display = "none";
                        }
                    }

                    setAlert(useExistingMsg, "Location linked to club.", "success");
                    showMsg("Location linked to club.", "success");
                })
                .catch((err) => setAlert(useExistingMsg, err.message || "Linking failed."));
        });

        // Create new location
        ml_createLinkBtn?.addEventListener("click", () => {
            const name = (ml_newFieldName?.value || "").trim();
            const addr = (ml_newAddress?.value || "").trim();
            if (!name) {
                setAlert(createNewMsg, "Field name is required.");
                return;
            }

            postForm("create_and_link_location.php", { club_uuid: clubUuid, field_name: name, address_text: addr })
                .then((data) => {
                    if (data.status !== "success") throw new Error(data.message || "Create failed");

                    locationUuid = data.location.uuid;
                    if (fieldNameText) fieldNameText.textContent = data.location.name || "—";
                    if (addressText) addressText.textContent = data.location.address_text || "—";
                    if (copyAddressBtn2) copyAddressBtn2.style.display = data.location.address_text ? "" : "none";
                    if (openMapsBtn) {
                        if (data.location.address_text) {
                            openMapsBtn.href = "https://maps.google.com/?q=" + encodeURIComponent(data.location.address_text);
                            openMapsBtn.style.display = "";
                        } else {
                            openMapsBtn.style.display = "none";
                        }
                    }

                    setAlert(createNewMsg, "Location created and linked.", "success");
                    showMsg("Location created and linked.", "success");
                })
                .catch((err) => setAlert(createNewMsg, err.message || "Create failed."));
        });
    }

    // ==========================================================
    // CLUBS LIST PAGE INITIALIZER (Add Club modal)
    // ==========================================================
    function initClubsListPage() {
        const addBtn = qs("#openAddClub");
        if (!addBtn) return; // not on clubs list page

        const modalEl = qs("#addClubModal");
        const modal = modalEl ? new bootstrap.Modal(modalEl) : null;
        const msg = qs("#addClubMsg");
        const nameInput = qs("#clubNameInput");
        const saveBtn = qs("#saveClubBtn");

        const clearValidation = () => nameInput?.classList.remove("is-invalid");
        const resetModal = () => {
            if (msg) msg.innerHTML = "";
            if (nameInput) nameInput.value = "";
            clearValidation();
        };
        const showModalMsg = (text, cls = "danger") => {
            if (!msg) return;
            msg.innerHTML = `<div class="alert alert-${cls} py-2 mb-2">${text}</div>`;
        };

        addBtn.addEventListener("click", () => {
            resetModal();
            modal?.show();
            setTimeout(() => nameInput?.focus(), 120);
        });

        saveBtn?.addEventListener("click", async () => {
            clearValidation();
            const clubName = (nameInput?.value || "").trim();
            if (!clubName) {
                nameInput?.classList.add("is-invalid");
                showModalMsg("Please enter a club name.");
                return;
            }
            try {
                const data = await postForm("clubs/add_club.php", { club_name: clubName });
                if (data.status !== "success") throw new Error(data.message || "Create failed");
                // Redirect to detail page for immediate editing
                window.location.href = "clubs/club-details.php?id=" + encodeURIComponent(data.club.uuid);
            } catch (e) {
                showModalMsg(e.message || "Server error.");
            }
        });
    }

    // Boot on DOM ready
    document.addEventListener("DOMContentLoaded", () => {
        initClubDetailsPage();
        initClubsListPage();
    });
})();
