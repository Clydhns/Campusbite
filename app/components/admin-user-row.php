                    <tr data-account-id="<?= (int) $user["user_id"] ?>"<?= $actionType === "updated" && (int) $user["user_id"] === $actionUserId ? ' class="account-updated"' : "" ?>>
                        <td><?= e($user["id_number"]) ?></td>
                        <td>
                            <?= e(
                                $user["first_name"] . " " .
                                $user["last_name"]
                            ) ?>
                        </td>
                        <td><?= e($user["email"]) ?></td>
                        <td>
                            <span class="badge">
                                <?= e($user["role_name"]) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($user["is_active"]): ?>
                                <span class="badge status-active">
                                    Active
                                </span>
                            <?php else: ?>
                                <span class="badge status-inactive">
                                    Inactive
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (
                                (int) $user["user_id"] === $admin_id
                            ): ?>
                                <span class="note">
                                    Current account
                                </span>
                            <?php else: ?>
                                <form method="POST" data-toggle-form>
                                    <input type="hidden" name="is_active" value="<?= $user["is_active"] ? '0' : '1' ?>">
                                    <input type="hidden"
                                           name="action"
                                           value="toggle_user">
                                    <input type="hidden"
                                           name="csrf_token"
                                           value="<?= e($csrf) ?>">
                                    <input type="hidden"
                                           name="user_id"
                                           value="<?= (int) $user["user_id"] ?>">

                                    <?php if ($user["is_active"]): ?>
                                        <button
                                            class="action-btn deactivate"
                                            type="submit">
                                            Deactivate
                                        </button>
                                    <?php else: ?>
                                        <button
                                            class="action-btn activate"
                                            type="submit">
                                            Activate
                                        </button>
                                    <?php endif; ?>
                                </form>
                                <?php if (
                                    !$user["is_active"] &&
                                    in_array(
                                        (int) $user["role_id"],
                                        [2, 3],
                                        true
                                    )
                                ): ?>
                                    <form method="POST" data-delete-form
                                          data-user-name="<?= e($user["first_name"] . " " . $user["last_name"]) ?>">
                                        <input type="hidden"
                                               name="action"
                                               value="delete_user">
                                        <input type="hidden"
                                               name="csrf_token"
                                               value="<?= e($csrf) ?>">
                                        <input type="hidden"
                                               name="user_id"
                                               value="<?= (int) $user["user_id"] ?>">
                                        <button
                                            class="action-btn deactivate"
                                            type="submit">
                                            Delete
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
