# Alerts, time travel and what-if

A twin watches its own readings. **Alerts** tell you when something passes a limit, the **timeline** lets you look back over the last day to see how it happened, and **what-if** runs the site ahead in time to show what an event would do before it happens.

![What-if: the refrigeration board trips now, and the freezer passes its limit in under four hours. The forecast compares cost, diesel, unserved load and stock at risk with and without the event.](../images/what-if.png)

## Alerts

Alerts come from the twin's **rules**, written in its [definition](12-twin-definitions.md#rules). A rule watches one metric, on one asset or on every asset of a kind, and raises an alert when it passes a threshold:

```text
Fire water low (warning):
    when fire-tank.levelPct < 60    → raise "Fire water tank is below 60%"
    clear when it's back above 65   → the gap stops it flickering on and off
```

A rule can also wait for a while (`forS`) before raising, so a door opened for a moment doesn't set it off. Two other kinds of rule watch how fast a metric changes (the freezer warming 2 °C in ten minutes) or an expression (the jockey pump running for more than 20 minutes); see [Rules](12-twin-definitions.md#rules).

When an alert is raised:

- The alerts button in the top bar turns amber for warnings, red for critical alerts, and counts them.
- A beacon rises over the asset in the scene, and the asset is marked in the list.
- A mark appears on the timeline at the moment it was raised.

### The Alerts tab

Choose the alerts button, or the **Alerts** tab in the inspector. Each alert shows its severity, the site time it was raised, and its message, with:

- **Show**: selects the asset and flies to it.
- **Replay from here**: moves the timeline back to the moment it was raised, so you can watch what led to it.
- **Acknowledge**: marks it as seen. It stays in the list until it clears.

Tick **Include cleared** to see alerts that have already cleared, with the time they did.

![The refrigeration board trip applied live, coloured by temperature: a beacon over the tripped board, de-energised chillers, both stores warming, and alerts on the timeline.](../images/cascade.png)

## Look back in time

The twin keeps the last 24 hours of site time. The timeline at the bottom is the site load over that day, with a mark for each alert.

1. Click or drag along the timeline. The clock in the top bar reads **Replay**, and the scene, list, readings and inspector all show that moment.
2. Use the alerts' **Replay from here** to jump straight to an alert.
3. Choose **Back to live** to return to now.

The model keeps running while you look back; nothing you see in the past changes it.

## What-if

What-if copies the twin exactly as it is now, runs the copy ahead twice, once as is and once with an event, and compares the two. Both runs share the same weather and the same random variation, so every difference between them comes from the event.

```text
now      ← the live state of the twin
baseline ← run now forward for the look-ahead, with nothing changed
event    ← run now forward for the look-ahead, with the event's faults applied
compare  ← cost, diesel used, load not served, stock at risk, critical alerts
show     ← each cold store's temperature in both runs, and the alerts the event raises
```

1. Choose the **What-if** tab in the inspector.
2. Under **Event**, choose what might happen. The samples include a grid outage, a chiller trip, a freezer door stuck open, a heatwave and bearing wear.
3. Choose how far to **Look ahead**: 2, 6, 12 or 24 hours.
4. Choose **Run forecast**. It takes a moment.

The results show:

- **The headline**: the first critical alert the event would cause, and how long until it does, or **No critical alerts** within the look-ahead.
- **Temperature forecasts** for each cold store, **No event** against **With event**, with the store's limit.
- **What would happen**: every alert the event would raise, in order.
- **The comparison**: **Run cost**, **Diesel**, **Load not served**, **Stock at risk** and **Critical alerts**, with and without the event.

### Apply an event to the live twin

**Apply to the live twin** makes the event happen for real in the running model. It's for drills and training: everyone watching the twin sees the cascade unfold, with the alerts it raises. Operators and up can apply events. While one is running, the What-if tab shows how many events are active in the model; choose **Clear** to end them early. Otherwise each fault ends when its duration does.

> When a gateway runs the twin, applying an event affects the shared model everyone sees, not just your browser.

## Tips

- **Forecast first, then decide.** "If Chiller 2's bearing goes, how long have we got?" is a two-click question.
- **Use replay after an incident.** Find the first alert, choose **Replay from here**, and step forward at 60× to see the order things happened in.

## See also

- [Twin definitions](12-twin-definitions.md): writing rules and scenarios.
- [Twins and the viewer](06-twins-and-the-viewer.md#the-timeline-bottom): the timeline controls.
