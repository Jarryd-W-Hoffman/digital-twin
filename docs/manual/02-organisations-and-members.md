# Organisations and members

Everything in Meridian belongs to an **organisation**: its projects, twins, uploads and devices. People join an organisation with a **role**, which decides what they can do in it. One person can belong to several organisations, with a different role in each.

![The Members page: everyone in Northshore Logistics with their role, and the form to add a member.](../images/members.png)

## Roles

|                                                                                 | Owner | Admin | Operator | Viewer |
| ------------------------------------------------------------------------------- | :---: | :---: | :------: | :----: |
| See projects and twins, open them, and watch them live                          |   ✓   |   ✓   |    ✓     |   ✓    |
| Send commands to a twin (setpoints, taking equipment out of service, scenarios) |   ✓   |   ✓   |    ✓     |        |
| Create projects and twins, upload, place, save and restore versions             |   ✓   |   ✓   |          |        |
| Issue gateway tokens, add devices, map telemetry                                |   ✓   |   ✓   |          |        |
| Add members and change roles                                                    |   ✓   |   ✓   |          |        |

- **Viewers** see everything but change nothing: the inspector's controls are disabled for them, and the twin ignores their commands.
- An organisation always keeps **at least one owner**. Demoting or removing the last owner is refused.
- Everything is private to the organisation: uploaded files are served only to its members, and a gateway's browsers need a pass signed for that user.

## Switch organisations

Choose the organisation's name at the top of the sidebar. The menu lists every organisation you belong to, with a tick beside the current one. Choose another to switch; the pages you see change to its projects and twins.

## Start a new organisation

1. Choose the organisation's name at the top of the sidebar, then **New organisation**.
2. Enter a **Name** and choose **Create**.

You're its owner, and Meridian switches to it straight away. It starts empty: [create a project](03-projects-and-the-library.md#create-a-project) next.

## Add a member

The person needs a Meridian account first: send them the sign-up link, or have them choose **Sign up** on the sign-in page.

1. Choose **Members** in the sidebar.
2. Under **Add a member**, enter their **Email** and choose a **Role**.
3. Choose **Add**.

They see the organisation in their switcher the next time they load a page.

## Change a role or remove someone

On **Members**, owners and admins see a role menu beside each person:

- **Change a role:** choose the new one from the menu. It applies at once.
- **Remove someone:** choose **Remove** at the end of their row and confirm. They lose access to everything in the organisation; nothing they made is deleted.

## Tips

- **Give people the smallest role that works.** A control room that only needs to watch and respond to alerts can be operators; people who only need to look can be viewers.
- **Use separate organisations for separate clients.** Nothing is shared between organisations, so a consultant can keep each client's sites apart and switch between them.

## See also

- [Projects and the library](03-projects-and-the-library.md): what editors can upload.
- [Your account](11-your-account.md): two-factor authentication and passkeys.
